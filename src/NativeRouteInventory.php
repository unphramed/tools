<?php

declare(strict_types=1);

namespace Unphramed\Tools;

/** Read-only discovery of native routes; this broader profile is never passed to the editor. */
final class NativeRouteInventory
{
    private const DIRECTIVES = ["set", "fastcgi_param", "add_header", "error_page", "valid_referers", "expires", "etag", "if_modified_since", "try_files"];

    public static function read(string $root, string $source): array
    {
        $result = ["routes" => [], "locations" => [], "diagnostics" => [], "checks" => []];

        try {
            self::walk($root, (new NginxSyntax($source))->parse(), $result);
        } catch (RouteError $error) {
            self::diagnostic($root, $result, $error->sourceLine, $error->getMessage());
        }

        return $result;
    }

    private static function diagnostic(string $root, array &$result, int $line, string $message): void
    {
        $result["diagnostics"][] = ["file" => $root . "/config/nginx.conf", "line" => $line, "message" => $message];
    }

    private static function walk(string $root, array $nodes, array &$result, array $conditions = [], ?string $location = null): void
    {
        foreach ($nodes as $node) {
            try {
                $args = $node["args"];

                if ($args[0] === "location") {
                    if ($node["children"] === null || !\in_array(\count($args), [2, 3], true) || (\count($args) === 3 && !\in_array($args[1], ["=", "~", "~*", "^~"], true))) {
                        throw new RouteError("Unsupported location shape");
                    }

                    if (\in_array($args[1], ["~", "~*"], true)) {
                        self::regex($args[2]);
                    }

                    // Keep the existing typed profile and its method/Allow verification intact.
                    try {
                        $typed = ["checks" => []];
                        $route = NginxRoutes::location($node, $typed);
                        self::append($root, $result, $route, $conditions);

                        foreach ($typed["checks"] as $check) {
                            $check["file"] = $root . "/config/nginx.conf";
                            $result["checks"][] = $check;
                        }
                    } catch (RouteError $error) {
                        // A method-selection profile must not silently lose its Allow checks.
                        if (self::methodSelection($node["children"])) {
                            throw $error;
                        }

                        self::walk($root, $node["children"], $result, [...$conditions, \implode(" ", $args)], \end($args));
                    }
                } elseif ($args[0] === "if" && $node["children"] !== null) {
                    self::walk($root, $node["children"], $result, [...$conditions, \implode(" ", $args)], $location);
                } elseif ($args[0] === "rewrite") {
                    if ($node["children"] !== null || \count($args) !== 4) {
                        throw new RouteError("Expected rewrite PATTERN TARGET FLAG");
                    }

                    self::regex($args[1]);

                    if (\in_array($args[3], ["permanent", "redirect"], true)) {
                        self::redirect($root, $result, $node, $args[1], $args[2], $args[3] === "permanent" ? 301 : 302, $conditions);
                    } elseif ($args[3] === "last") {
                        try {
                            $route = RewriteRoutes::read($root, $node);
                            self::append($root, $result, $route, $conditions);
                        } catch (RouteError) {
                            self::endpoints($root, $result, $node, $args[1], $args[2], $conditions);
                        }
                    } else {
                        throw new RouteError("Application rewrites must use last; redirects use permanent or redirect");
                    }
                } elseif ($args[0] === "return" && $node["children"] === null) {
                    if (\count($args) === 3 && \in_array($args[1], ["301", "302", "303", "307", "308"], true)) {
                        if ($location === null) {
                            throw new RouteError("A redirect return needs a location path");
                        }

                        self::redirect($root, $result, $node, $location, $args[2], (int) $args[1], $conditions);
                    } elseif (\count($args) < 2 || !\ctype_digit($args[1]) || \count($args) > 3) {
                        throw new RouteError("Unsupported return directive");
                    }
                } elseif ($node["children"] !== null || !\in_array($args[0], self::DIRECTIVES, true)) {
                    throw new RouteError("Unsupported application directive: " . $args[0] . ". Expected a fragment included inside the environment server block.");
                }
            } catch (RouteError $error) {
                self::diagnostic($root, $result, $node["line"], $error->getMessage());
            }
        }
    }

    private static function methodSelection(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node["args"][0] === "add_header" && ($node["args"][1] ?? "") === "Allow") {
                return true;
            }

            if ($node["args"][0] === "if" && \str_contains(\implode(" ", $node["args"]), '$request_method')) {
                foreach ($node["children"] ?? [] as $child) {
                    if ($child["args"][0] === "set" || $child["args"] === ["return", "405"]) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function append(string $root, array &$result, array $route, array $conditions): void
    {
        $node = $route["node"];
        $metadata = ["pattern" => $route["pattern"] ?? $route["path"], "target" => $node["args"][0] === "rewrite" ? $node["args"][2] : ($route["redirect"]["target"] ?? null), "conditions" => $conditions, "editable" => false];
        $base = ["file" => $root . "/config/nginx.conf", "line" => $node["line"], "offset" => $node["start"], "kind" => $route["kind"], "path" => $route["path"], "parameters" => $route["parameters"] ?? [], "parameter" => $route["parameters"][0]["name"] ?? null] + $metadata;

        if ($route["kind"] === "redirect") {
            $result["routes"][] = $base + ["methods" => [], "endpoint" => null, "redirect" => $route["redirect"]];
            return;
        }

        $groups = [];

        foreach ($route["bindings"] as $method => $endpoint) {
            $groups[$endpoint][] = $method;
        }

        foreach ($groups as $endpoint => $methods) {
            $result["routes"][] = $base + ["methods" => $methods, "endpoint" => $endpoint, "redirect" => null];
        }
    }

    private static function redirect(string $root, array &$result, array $node, string $pattern, string $target, int $status, array $conditions): void
    {
        if (!\str_starts_with($target, "/") || \str_starts_with($target, "//") || \preg_match("/[\\s\\r\\n]/", $target)) {
            throw new RouteError("Only local redirect targets are interpreted");
        }

        self::targetCaptures($pattern, $target);
        self::append($root, $result, ["node" => $node, "path" => $pattern, "pattern" => $pattern, "kind" => "redirect", "redirect" => ["status" => $status, "target" => $target]], $conditions);
        $result["routes"][\array_key_last($result["routes"])]["editable"] = false;
    }

    private static function regex(string $pattern): string
    {
        $regex = "~" . \str_replace("~", "\\~", $pattern) . "~";

        if (@\preg_match($regex, "") === false) {
            throw new RouteError("Invalid route regular expression");
        }

        return $regex;
    }

    /** Number captures while respecting escaped parentheses, character classes and nested groups. */
    private static function captures(string $pattern): array
    {
        $stack = [];
        $captures = [];
        $number = 0;
        $class = false;

        for ($i = 0; $i < \strlen($pattern); $i++) {
            $char = $pattern[$i];

            if ($char === "\\") {
                $i++;
                continue;
            }

            if ($char === "[") {
                $class = true;
            }

            if ($char === "]") {
                $class = false;
            }

            if ($class) {
                continue;
            }

            if ($char === "(") {
                $capturing = ($pattern[$i + 1] ?? "") !== "?";
                $start = $i + 1;

                if (\preg_match("/\\A\\(\\?<([A-Za-z_][A-Za-z0-9_]*)>/", \substr($pattern, $i), $match)) {
                    $capturing = true;
                    $start = $i + \strlen($match[0]);
                }

                $stack[] = [$capturing ? ++$number : null, $start];
            } elseif ($char === ")" && $stack) {
                [$index, $start] = \array_pop($stack);

                if ($index !== null) {
                    $captures[$index] = \substr($pattern, $start, $i - $start);
                }
            }
        }

        return $captures;
    }

    /** Enumerate literal alternatives/optional groups; unbounded expressions use existing-file matching. */
    private static function alternatives(string $expression, int &$position = 0): ?array
    {
        $choices = [];
        $words = [""];

        while ($position < \strlen($expression)) {
            $char = $expression[$position++];

            if ($char === ")" || $char === "|") {
                $choices = \array_merge($choices, $words);

                if ($char === ")") {
                    return $choices;
                }

                $words = [""];
                continue;
            }

            if ($char === "(") {
                if (\substr($expression, $position, 2) === "?:") {
                    $position += 2;
                }

                $parts = self::alternatives($expression, $position);

                if ($parts === null) {
                    return null;
                }
            } elseif ($char === "\\" && \in_array($expression[$position] ?? "", [".", "-", "/"], true)) {
                $parts = [$expression[$position++]];
            } elseif (\preg_match("/[A-Za-z0-9_\\/-]/", $char)) {
                $parts = [$char];
            } else {
                return null;
            }

            if (($expression[$position] ?? "") === "?") {
                $parts[] = "";
                $position++;
            }

            $next = [];

            foreach ($words as $word) {
                foreach ($parts as $part) {
                    $next[] = $word . $part;

                    if (\count($next) > 512) {
                        return null;
                    }
                }
            }

            $words = $next;
        }

        return \array_merge($choices, $words);
    }

    private static function endpoints(string $root, array &$result, array $node, string $pattern, string $target, array $conditions): void
    {
        $head = \str_contains($target, '$handler_method');
        $target = \str_replace('$handler_method', '$request_method', $target);
        $script = \explode("?", $target, 2)[0];

        if (!\str_starts_with($script, "/") || !\str_ends_with($script, ".php")) {
            throw new RouteError("Expected a rewrite to a PHP script under web/");
        }

        $captures = self::targetCaptures($pattern, $target);
        $query = \explode("?", $target, 2)[1] ?? "";

        if (\str_starts_with($query, '$args&')) {
            $names = [];
            $references = [];

            foreach (\explode("&", \rtrim(\substr($query, 6), "?")) as $pair) {
                if (!\preg_match('/\A([a-z][a-z0-9_]*)=\$([1-9])\z/D', $pair, $match) || isset($names[$match[1]]) || isset($references[$match[2]])) {
                    $result["checks"][] = ["file" => $root . "/config/nginx.conf", "line" => $node["line"], "message" => 'Use unique query names bound to positional captures in the $args query profile'];
                    break;
                }

                $names[$match[1]] = $references[$match[2]] = true;
            }
        }

        $templates = ["web" . $script];
        $fileMatching = false;
        \preg_match_all('/\$([1-9][0-9]*)/', $script, $references);

        foreach (\array_unique($references[1]) as $index) {
            if (!isset($captures[$index])) {
                throw new RouteError('Unknown target capture: $' . $index);
            }

            $values = self::alternatives($captures[$index]);

            if ($values === null) {
                $fileMatching = true;
                continue;
            }

            $next = [];

            foreach ($templates as $template) {
                foreach (\array_unique($values) as $value) {
                    $next[] = \preg_replace('/\$' . $index . "(?![0-9])/", $value, $template);

                    if (\count($next) > 512) {
                        throw new RouteError("Too many target alternatives to verify");
                    }
                }
            }

            $templates = $next;
        }

        $dynamic = \str_contains($script, '$request_method');
        $methods = $dynamic && \is_dir($root . "/web") ? \array_values(\array_filter(\scandir($root . "/web"), static fn($name) => \preg_match("/\\A[A-Z_-]+\\z/D", $name) && !\in_array($name, ["TRACE", "CONNECT"], true))) : ["*"];
        $bindings = [];

        foreach ($templates as $template) {
            if (\preg_match('/\$(?!request_method(?:\/|$)|[1-9][0-9]*)/', $template)) {
                throw new RouteError("Unresolved variable in PHP target: " . $script);
            }

            if ($fileMatching) {
                $endpointRegex = "";

                foreach (\preg_split('/(\$request_method|\$[1-9][0-9]*)/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
                    $endpointRegex .= $part === '$request_method' ? "(?<native_method>[A-Z_-]+)" : (\preg_match('/\A\$([1-9][0-9]*)\z/D', $part, $match) ? "(?:" . $captures[$match[1]] . ")" : \preg_quote($part, "~"));
                }

                $regex = self::regex("\\A" . $endpointRegex . "\\z");

                if (\is_dir($root . "/web")) {
                    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . "/web", \FilesystemIterator::SKIP_DOTS));

                    foreach ($files as $file) {
                        $endpoint = \substr($file->getPathname(), \strlen($root) + 1);

                        if (\preg_match($regex, $endpoint, $match)) {
                            $bindings[$endpoint] = $dynamic ? ($match["native_method"] ?? "*") : "*";
                        }
                    }
                }
            } else {
                foreach ($methods as $method) {
                    $endpoint = \str_replace('$request_method', $method, $template);
                    self::endpoint($endpoint);
                    $file = RouteEditor::path($root, $endpoint);

                    if (!$dynamic || \is_file($file)) {
                        $bindings[$endpoint] = $method;
                    }
                }
            }
        }

        \ksort($bindings);

        if (!$bindings) {
            $result["checks"][] = ["file" => $root . "/config/nginx.conf", "line" => $node["line"], "message" => "No available PHP endpoints for target: " . $target];
        }

        foreach ($bindings as $endpoint => $method) {
            self::endpoint($endpoint);
            RouteEditor::path($root, $endpoint);
            self::append($root, $result, ["node" => $node, "path" => $pattern, "pattern" => $pattern, "kind" => "endpoint", "bindings" => $head && $method === "GET" ? ["GET" => $endpoint, "HEAD" => $endpoint] : [$method => $endpoint]], $conditions);
            $result["routes"][\array_key_last($result["routes"])]["editable"] = false;
        }
    }

    private static function endpoint(string $endpoint): void
    {
        if (!\preg_match("~\\Aweb/(?:[A-Za-z0-9_.\\[\\]-]+/)*[A-Za-z0-9_.\\[\\]-]+\\.php\\z~D", $endpoint) || \str_contains($endpoint, "..")) {
            throw new RouteError("Select a PHP file under web/, without traversal");
        }
    }

    private static function targetCaptures(string $pattern, string $target): array
    {
        $captures = self::captures($pattern);
        \preg_match_all('/\$([1-9][0-9]*)/', $target, $references);

        foreach ($references[1] as $index) {
            if (!isset($captures[$index])) {
                throw new RouteError('Unknown target capture: $' . $index);
            }
        }

        return $captures;
    }
}
