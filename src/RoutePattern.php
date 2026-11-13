<?php

declare(strict_types=1);

namespace Unphramed\Tools;

/** Convert an explicit regex into the existing, validated development-time contract. */
final class RoutePattern
{
    public static function options(string $pattern, bool $interactive = false): array
    {
        if (\str_starts_with($pattern, "^")) {
            if (!\str_ends_with($pattern, '$')) {
                throw new RouteError("Anchor both ends of the route pattern");
            }

            $pattern = \substr($pattern, 1, -1);
        } elseif (\str_ends_with($pattern, '$')) {
            throw new RouteError("Anchor both ends of the route pattern");
        }

        if (!\str_starts_with($pattern, "/")) {
            throw new RouteError("Route pattern must start with /");
        }

        $options = ["--named" => true, "--constraint" => [], "--choices" => [], "--unused" => []];
        $names = [];
        $number = 0;
        $convert = function (string $text) use (&$convert, &$options, &$names, &$number, $interactive): string {
            $output = "";
            $length = \strlen($text);

            for ($i = 0; $i < $length; $i++) {
                $char = $text[$i];

                if ($char === "\\") {
                    $next = $text[++$i] ?? "";

                    if (!\in_array($next, [".", "-", "~"], true)) {
                        throw new RouteError("Unsupported escape outside a capture");
                    }

                    $output .= $next;
                    continue;
                }

                if ($char !== "(") {
                    if (!\preg_match('~[A-Za-z0-9/_-]~', $char)) {
                        throw new RouteError("Regex parameters must be enclosed in captures; escape literal dots");
                    }

                    $output .= $char;
                    continue;
                }

                $start = $i;
                $depth = 1;
                $inCharacterClass = false;

                for ($i++; $i < $length; $i++) {
                    if ($text[$i] === "\\") {
                        $i++;
                        continue;
                    }

                    if ($text[$i] === "[" && !$inCharacterClass) {
                        $inCharacterClass = true;
                        continue;
                    }

                    if ($text[$i] === "]" && $inCharacterClass) {
                        $inCharacterClass = false;
                        continue;
                    }

                    if ($inCharacterClass) {
                        continue;
                    }

                    if ($text[$i] === "(") {
                        $depth++;
                    }

                    if ($text[$i] === ")") {
                        $depth--;

                        if ($depth === 0) {
                            break;
                        }
                    }
                }

                if ($depth !== 0) {
                    throw new RouteError("Unterminated regex group");
                }

                $body = \substr($text, $start + 1, $i - $start - 1);

                if (\str_starts_with($body, "?:/")) {
                    if (($text[$i + 1] ?? "") !== "?") {
                        throw new RouteError("Only optional whole segments may use structural groups");
                    }

                    $part = $convert(\substr($body, 2));

                    if (!\preg_match('~\A/\{([a-z][A-Za-z0-9]*):(int|slug|string)\}\z~D', $part, $match)) {
                        throw new RouteError("Optional groups must contain one parameter segment");
                    }

                    $output .= "/{" . $match[1] . ":" . $match[2] . "?}";
                    $i++;
                    continue;
                }

                $ignored = \str_starts_with($body, "?:");

                if ($ignored) {
                    $body = \substr($body, 2);

                    if (!\preg_match('/\A[A-Za-z0-9_-]+(?:\|[A-Za-z0-9_-]+)+\z/D', $body)) {
                        throw new RouteError("Noncapturing parameter segments require finite literal choices");
                    }

                    $name = "ignored" . ++$number;
                    $options["--choices"][] = $name . "=" . \str_replace("|", ",", $body);
                    $options["--unused"][] = $name;
                } elseif (\preg_match('/\A\?<([a-z][A-Za-z0-9]*)>(.*)\z/sD', $body, $match)) {
                    [, $name, $body] = $match;
                } else {
                    if (\str_starts_with($body, "?")) {
                        throw new RouteError("Unsupported regex group");
                    }

                    $number++;

                    if (!$interactive) {
                        throw new RouteError("Name anonymous captures with (?<name>...) when not using a terminal");
                    }

                    echo "Name for capture ", $number, " (", $body, "): ";
                    $answer = \fgets(STDIN);

                    if ($answer === false) {
                        throw new RouteError("Cancelled: missing capture name");
                    }

                    $name = \trim($answer);
                }

                if (!\preg_match('/\A[a-z][A-Za-z0-9]*\z/D', $name) || isset($names[$name])) {
                    throw new RouteError("Use unique lowerCamelCase capture names");
                }

                $names[$name] = true;

                if (!$ignored) {
                    $options["--constraint"][] = $name . "=" . $body;
                }

                if (($text[$i + 1] ?? "") === "?") {
                    throw new RouteError("Use (?:/(?<name>...))? for an optional whole segment");
                }

                $type = \array_search($body, RoutePath::TYPES, true) ?: "string";
                $output .= "{" . $name . ":" . $type . "}";
            }

            return $output;
        };
        $options["--path"] = $convert($pattern);
        // Reuse filename, query-alphabet and construction validation, never execute application code.
        RoutePath::describe($options["--path"], $options);
        return $options;
    }
}
