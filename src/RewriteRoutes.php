<?php

declare(strict_types=1);

namespace Unphramed\Tools;

/** Development-time interpretation of server-level rewrites, never an HTTP dispatcher. */
final class RewriteRoutes
{
    public static function read(string $root, array $node, array $plannedEndpoints = []): array
    {
        $args = $node["args"];
        if ($node["children"] !== null || \count($args) !== 4) throw new RouteError("Expected a native rewrite directive");
        [$directive, $pattern, $target, $flag] = $args;
        if (\in_array($flag, ["permanent", "redirect"], true)) {
            $description = self::description($pattern, []);
            if ($description["parameters"] || !\preg_match("~\\A/[A-Za-z0-9/_-]*\\z~D", $target)) throw new RouteError("Only literal local rewrite redirects are supported");
            return $description + ["node" => $node, "kind" => "redirect", "profile" => "rewrite", "redirect" => ["status" => $flag === "permanent" ? 301 : 302, "target" => $target]];
        }
        if ($flag !== "last") throw new RouteError("Application rewrites must use last to reach the common PHP handler");
        $description = isset($node["schema"]) ? RoutePath::describe($node["schema"]["path"], $node["schema"]["options"] ?? []) : null;
        $normalizations = [];
        if ($node["schema"]["options"]["--named"] ?? false) {
            foreach ($description["parameters"] as $index => $parameter) {
                $normalizations['$' . $parameter["capture"]] = '$' . ($index + 1);
            }
            $target = \strtr($target, $normalizations);
            $args[2] = $target;
        }
        $parts = \explode("?", $target, 2);
        $script = $parts[0];
        $arguments = [];
        $queryMode = "preserve";
        if (isset($parts[1])) {
            if (!\str_ends_with($parts[1], "?")) throw new RouteError("Terminate the query replacement with ?");
            $preserve = \str_starts_with($parts[1], '$args&');
            $queryMode = $preserve ? "append-captures" : "replace";
            foreach (\explode("&", \substr($parts[1], $preserve ? 6 : 0, -1)) as $pair) {
                if (!\preg_match('/\A([a-z][A-Za-z0-9_]*)=\$([1-9])\z/D', $pair, $match) || isset($arguments[(int) $match[2]]) || \in_array($match[1], $arguments, true)) {
                    throw new RouteError("Use unique query names bound to positional captures");
                }
                $arguments[(int) $match[2]] = $match[1];
            }
        }
        $description ??= self::description($pattern, $arguments);
        if (isset($node["schema"])) {
            $expected = (new NginxSyntax(self::text($description, "web" . $script, $queryMode === "replace")))->parse()[0]["args"];
            $expected[2] = \strtr($expected[2], $normalizations);
            if ($expected !== $args) throw new RouteError("Route schema comment disagrees with its native rewrite");
            foreach ($description["parameters"] as $index => &$parameter) {
                $parameter["source"] = "query";
                $parameter["queryKey"] = $parameter["name"];
                $parameter["capture"] = '$' . ($index + 1);
            }
            unset($parameter);
        }
        $dynamic = \str_starts_with($script, '/$request_method/') || \str_starts_with($script, '/$handler_method/');
        $endpointPattern = "web" . $script;
        RoutePath::endpoint(\str_replace(['$request_method', '$handler_method'], "GET", $endpointPattern));
        $bindings = $dynamic ? self::bindings($root, $endpointPattern, $plannedEndpoints) : ["*" => $endpointPattern];
        return $description + ["node" => $node, "kind" => "endpoint", "profile" => "rewrite", "endpointPattern" => $endpointPattern, "bindings" => $bindings, "queryMode" => $queryMode];
    }

    public static function bindings(string $root, string $pattern, array $plannedEndpoints = []): array
    {
        $bindings = [];
        $methods = \is_dir($root . "/web") ? \scandir($root . "/web") : [];
        foreach ($plannedEndpoints as $file) {
            if (\preg_match("~\\Aweb/([A-Z_-]+)/~D", $file, $match)) $methods[] = $match[1];
        }
        $methods = \array_unique($methods);
        \sort($methods);
        foreach ($methods as $method) {
            if (!\preg_match("/\\A[A-Z_-]+\\z/D", $method) || \in_array($method, ["TRACE", "CONNECT"], true)) continue;
            $normalized = \str_contains($pattern, '$handler_method');
            if ($normalized && $method === "HEAD") continue;
            $endpoint = \str_replace(['$request_method', '$handler_method'], $method, $pattern);
            $file = RouteEditor::path($root, $endpoint);
            if (\is_file($file) || \in_array($endpoint, $plannedEndpoints, true)) $bindings[$method] = $endpoint;
        }
        if (\str_contains($pattern, '$handler_method') && isset($bindings["GET"])) $bindings["HEAD"] = $bindings["GET"];
        return $bindings;
    }

    private static function description(string $pattern, array $arguments): array
    {
        if (!\str_starts_with($pattern, "^") || !\str_ends_with($pattern, '$')) throw new RouteError("Rewrite paths must be anchored");
        $path = \substr($pattern, 1, -1);
        $parameters = [];
        $position = 0;
        // Only captures that remain safe when inserted into a query string are accepted.
        $expressions = ["int" => ["[1-9][0-9]{0,8}", "\\d+"], "slug" => [RoutePath::TYPES["slug"]]];
        while (\preg_match("/\\(/", $path, $opening, PREG_OFFSET_CAPTURE)) {
            $start = $opening[0][1];
            $matched = false;
            foreach ($expressions as $type => $variants) {
                foreach ($variants as $expression) {
                    $capture = "(" . $expression . ")";
                    if (\substr($path, $start, \strlen($capture)) !== $capture) continue;
                    $position++;
                    $name = $arguments[$position] ?? null;
                    if ($name === null) throw new RouteError("Each rewrite capture needs one query argument");
                    $parameters[] = ["name" => $name, "type" => $type, "capture" => '$' . $position, "source" => "query", "queryKey" => $name];
                    $path = \substr($path, 0, $start) . "{" . $name . ":" . $type . "}" . \substr($path, $start + \strlen($capture));
                    $matched = true;
                    break 2;
                }
            }
            if (!$matched) throw new RouteError("Use numeric/slug rewrite captures; arbitrary strings need the optional location/request-path profile");
        }
        if (\count($parameters) !== \count($arguments)) throw new RouteError("Every query binding must reference one route capture");
        $description = RoutePath::describe(\str_replace(["\\.", "\\-", "\\~"], [".", "-", "~"], $path));
        $description["parameters"] = $parameters;
        $description["pattern"] = $pattern;
        unset($description["schema"]);
        return $description;
    }

    public static function schema(string $source, array $node): array
    {
        $before = \substr($source, 0, $node["start"]);
        if (\preg_match('/# route-schema (\{[^\n]+\})\s*$/', $before, $match)) {
            $node["schema"] = \json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        }
        return $node;
    }

    public static function comment(array $description): string
    {
        if (!isset($description["schema"])) return "";
        return "# route-schema " . \json_encode($description["schema"], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    public static function querySafe(array $parameter): bool
    {
        $expression = $parameter["expression"] ?? RoutePath::TYPES[$parameter["type"]];
        if (($parameter["unused"] ?? false) && $expression === RoutePath::TYPES["string"]) return true;
        // A deliberately small alphabet: every accepted capture is safe in a raw query value.
        $safe = true;
        $remaining = \preg_replace_callback('/\[(?:[A-Za-z0-9_-]|\\\\d)+\]/', static function ($match) use (&$safe) {
            for ($byte = 0; $byte < 256; $byte++) {
                $character = \chr($byte);
                if (\preg_match("~" . $match[0] . "~D", $character) && !\preg_match('/\A[A-Za-z0-9._~-]\z/D', $character)) $safe = false;
            }
            return "a";
        }, $expression);
        if (!$safe) return false;
        $remaining = \str_replace(["\\d", "\\w", "\\.", "(?:"], ["a", "a", "a", "("], $remaining);
        return (bool) \preg_match('/\A[A-Za-z0-9_()|?*+{},-]+\z/D', $remaining);
    }

    public static function text(array $description, string $endpointPattern, bool $replaceQuery = false): string
    {
        if (\count($description["parameters"]) > 9) throw new RouteError("This rewrite profile supports at most nine captures");
        $namedCaptures = $description["schema"]["options"]["--named"] ?? false;
        $pattern = $description["pattern"];
        if (!\str_starts_with($pattern, "^")) $pattern = "^" . \preg_quote($pattern, "~") . '$';
        foreach ($description["parameters"] as $parameter) {
            if (!$namedCaptures) {
                $pattern = \str_replace("(?<" . $parameter["capture"] . ">", "(", $pattern);
            }
            if (!self::querySafe($parameter)) throw new RouteError("Arbitrary string captures need the optional location/request-path profile");
        }
        $target = \substr($endpointPattern, 3);
        if ($description["parameters"]) {
            $pairs = [];
            foreach ($description["parameters"] as $index => $parameter) {
                if ($parameter["unused"] ?? false) continue;
                $capture = $namedCaptures ? $parameter["capture"] : $index + 1;
                $pairs[] = $parameter["name"] . '=$' . $capture;
            }
            if (!$pairs) return 'rewrite "' . $pattern . '" ' . $target . " last;";
            $target .= ($replaceQuery ? "?" : '?$args&') . \implode("&", $pairs);
            $target .= "?";
        }
        return 'rewrite "' . $pattern . '" ' . $target . " last;";
    }
}
