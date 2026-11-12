<?php

declare(strict_types=1);

namespace Unphramed\Tools;

/** Restricted virtual-host rewrite profile, parsed without running Apache or application code. */
final class ApacheRoutes
{
    private static function nodes(string $source): array
    {
        $nodes = [];
        $offset = 0;
        foreach (\explode("\n", $source) as $index => $line) {
            $text = \trim($line);
            if ($text !== "" && !\str_starts_with($text, "#")) {
                $args = [];
                while ($text !== "") {
                    if (!\preg_match('~\A(?:"([^"\r\n]*)"|\x27([^\x27\r\n]*)\x27|([^\s"\x27]+))(?:\s+|\z)~', $text, $match)) {
                        throw new RouteError("Unsupported Apache quoting or continuation", $index + 1);
                    }
                    $args[] = $match[3] ?? ($match[2] ?? $match[1]);
                    $text = \substr($text, \strlen($match[0]));
                }
                $nodes[] = ["args" => $args, "line" => $index + 1, "start" => $offset, "end" => $offset + \strlen($line), "children" => null];
            }
            $offset += \strlen($line) + 1;
        }
        return $nodes;
    }

    private static function flags(array $args): array
    {
        if (\count($args) === 3) return [];
        if (\count($args) !== 4 || !\preg_match('/\A\[([A-Za-z0-9=,]+)\]\z/D', $args[3], $match)) throw new RouteError("Unsupported RewriteRule flags");
        $flags = \explode(",", \strtoupper($match[1]));
        \sort($flags);
        if (\count($flags) !== \count(\array_unique($flags))) throw new RouteError("Duplicate RewriteRule flag");
        return $flags;
    }

    public static function read(string $root, ?string $source = null, array $plannedEndpoints = []): array
    {
        $file = $root . "/config/apache.conf";
        $source ??= \file_get_contents($file);
        $result = ["routes" => [], "locations" => [], "diagnostics" => [], "checks" => [], "sharedDispatch" => false, "insertOffset" => \strlen($source)];
        try {
            $nodes = self::nodes($source);
            $engine = false;
            $handlerMethod = false;
            $handlerHead = false;
            $guard = false;
            $pending = [];
            $styles = [];
            $fallback = false;
            foreach ($nodes as $index => $node) {
                $node = RewriteRoutes::schema($source, $node);
                $args = $node["args"];
                try {
                    if ($fallback) throw new RouteError("Rules after the 404 fallback are unreachable");
                    if (\strtolower($args[0]) === "rewriteengine") {
                        if ($engine || $guard || $pending || $result["locations"] || \count($args) !== 2 || \strtolower($args[1]) !== "on") throw new RouteError("Start the fragment with RewriteEngine On");
                        $engine = true;
                        continue;
                    }
                    if (!$engine) throw new RouteError("Missing RewriteEngine On before rules");
                    if (\strtolower($args[0]) === "rewritecond") {
                        $pending[] = $node;
                        continue;
                    }
                    if (\strtolower($args[0]) !== "rewriterule" || \count($args) < 3) throw new RouteError("Only application RewriteRule/RewriteCond directives are supported");
                    $flags = \in_array($args[3] ?? "", ["[E=handler_method:%{REQUEST_METHOD}]", "[E=handler_method:GET]"], true) ? [] : self::flags($args);
                    if ($args[1] === '\.php(?:/|$)' && $args[2] === "-" && $flags === ["L", "R=404"] && !$pending) {
                        if ($guard || $result["locations"]) throw new RouteError("Place one direct-script guard before endpoint rules");
                        $guard = true;
                        continue;
                    }
                    if (!$guard) throw new RouteError("Place direct-script protection before endpoint rules");
                    if ($args[1] === '^/.*\.php$' && $args[2] === "-" && $flags === ["PT"] && \count($pending) === 1 && $pending[0]["args"] === ["RewriteCond", '%{DOCUMENT_ROOT}$0', "-f"]) {
                        if ($result["sharedDispatch"]) throw new RouteError("Duplicate shared dispatch block");
                        $next = $nodes[$index + 1]["args"] ?? [];
                        if (\count($next) !== 4 || \strtolower($next[0]) !== "rewriterule" || $next[1] !== "^" || $next[2] !== "-" || self::flags($next) !== ["L", "R=404"]) throw new RouteError("Shared dispatch must end with a 404 fallback");
                        $result["sharedDispatch"] = true;
                        $result["insertOffset"] = $pending[0]["start"];
                        $pending = [];
                        continue;
                    }
                    if ($args[1] === "^" && $args[2] === "-" && $flags === ["L", "R=404"] && !$pending) {
                        $fallback = true;
                        if (!$result["sharedDispatch"]) $result["insertOffset"] = $node["start"];
                        continue;
                    }
                    if ($args === ["RewriteRule", "^", "-", "[E=handler_method:%{REQUEST_METHOD}]"] && !$pending) { $handlerMethod = true; continue; }
                    if ($args === ["RewriteRule", "^", "-", "[E=handler_method:GET]"] && \count($pending) === 1 && $pending[0]["args"] === ["RewriteCond", "%{REQUEST_METHOD}", "^HEAD$"] && $handlerMethod) { $handlerHead = true; $pending = []; continue; }
                    if (\str_contains($args[2], "%{ENV:handler_method}") && !$handlerHead) throw new RouteError("Missing HEAD selection helpers before rewrite");
                    $methods = null;
                    if ($pending) {
                        if (\count($pending) !== 1 || \count($pending[0]["args"]) !== 3 || $pending[0]["args"][1] !== "%{REQUEST_METHOD}" || !\preg_match('/\A\^(?:\(\?:([A-Z_|-]+)\)|([A-Z_-]+))\$\z/D', $pending[0]["args"][2], $match)) throw new RouteError("Unsupported route condition");
                        $methods = \explode("|", $match[2] ?? $match[1]);
                        foreach ($methods as $method) RoutePath::method($method);
                        if (\count($methods) !== \count(\array_unique($methods))) throw new RouteError("Duplicate method association");
                        $node["start"] = $pending[0]["start"];
                        $node["line"] = $pending[0]["line"];
                    }
                    $pending = [];
                    $target = \str_replace(["%{REQUEST_METHOD}", "%{ENV:handler_method}"], ['$request_method', '$handler_method'], $args[2]);
                    if (\str_contains($target, "?")) $target .= "?";
                    $redirect = null;
                    if (\count($flags) === 2 && $flags[0] === "L" && \preg_match('/\AR=(301|302|303|307|308)\z/D', $flags[1], $match)) $redirect = (int) $match[1];
                    if ($redirect !== null) {
                        if ($methods !== null) throw new RouteError("Conditional redirects are not supported");
                        $translated = ["rewrite", $args[1], $args[2], "redirect"];
                    } else {
                        if ($flags !== [] && $flags !== ["PT"] && $flags !== ["L", "PT"]) throw new RouteError("Use shared dispatch or a direct [PT] rule");
                        $styles[] = $flags ? "direct" : "shared";
                        $translated = ["rewrite", $args[1], $target, "last"];
                    }
                    $location = RewriteRoutes::read($root, ["args" => $translated, "children" => null] + RewriteRoutes::schema($source, $node), $plannedEndpoints);
                    $location["node"] = $node;
                    $location["apacheDirect"] = $redirect === null && $flags !== [];
                    if ($redirect !== null) $location["redirect"]["status"] = $redirect;
                    if ($methods !== null) {
                        if (\str_contains($location["endpointPattern"], '$request_method')) throw new RouteError("Method conditions require a fixed PHP target");
                        $location["bindings"] = \array_fill_keys($methods, $location["endpointPattern"]);
                    }
                    $result["locations"][] = $location;
                } catch (RouteError $error) {
                    $result["diagnostics"][] = ["file" => $file, "line" => $pending[0]["line"] ?? $node["line"], "message" => $error->getMessage()];
                    $pending = [];
                }
            }
            if (!$engine || !$guard) throw new RouteError("Expected RewriteEngine On and direct-script protection");
            if ($pending) throw new RouteError("RewriteCond requires a following rule", $pending[0]["line"]);
            if (\in_array("shared", $styles, true) && !$result["sharedDispatch"]) throw new RouteError("Endpoint rules without [PT] require the shared dispatch block");
        } catch (RouteError $error) {
            $result["diagnostics"][] = ["file" => $file, "line" => $error->sourceLine, "message" => $error->getMessage()];
        }
        $result["routes"] = NginxRoutes::records($file, $result["locations"]);
        return $result;
    }
}
