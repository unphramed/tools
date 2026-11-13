<?php

declare(strict_types=1);

namespace Unphramed\Tools;

final class RoutePath
{
    public const TYPES = ["int" => "[1-9][0-9]{0,8}", "slug" => "[a-z0-9]+(?:-[a-z0-9]+)*", "string" => "[^/]{1,255}"];

    public static function describe(string $path, array $options = []): array
    {
        if (!\str_starts_with($path, "/") || \str_contains($path, "//")) throw new RouteError("Use an absolute route schema");
        $path = $path === "/" ? $path : \rtrim($path, "/");
        $constraints = self::assignments($options["--constraint"] ?? []);
        $choices = self::assignments($options["--choices"] ?? []);
        $unused = $options["--unused"] ?? [];
        $parameters = [];
        $filename = [];
        $pattern = "";
        $canonical = [];
        foreach ($path === "/" ? [] : \explode("/", \substr($path, 1)) as $segment) {
            $part = "";
            $namePart = "";
            $canonicalPart = "";
            $optional = false;
            $offset = 0;
            \preg_match_all('/\{([a-z][A-Za-z0-9_]*)(?::(int|slug|string))?(\?)?\}/', $segment, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $literal = \substr($segment, $offset, $match[0][1] - $offset);
                self::literal($literal);
                $part .= \preg_quote($literal, "~");
                $namePart .= $literal;
                $canonicalPart .= $literal;
                $name = $match[1][0];
                if (isset($parameters[$name])) throw new RouteError("Duplicate route parameter: " . $name);
                $type = ($match[2][0] ?? "") ?: "int";
                $isOptional = ($match[3][0] ?? "") === "?";
                if ($isOptional && $match[0][0] !== $segment) throw new RouteError("Optional parameters must occupy a whole segment");
                $optional = $isOptional;
                $expression = $constraints[$name] ?? self::TYPES[$type];
                if (isset($choices[$name])) {
                    if (isset($constraints[$name])) throw new RouteError("Use either choices or a constraint for " . $name);
                    $values = \explode(",", $choices[$name]);
                    foreach ($values as $value) {
                        if ($value === "" || !\preg_match('/\A[A-Za-z0-9._-]+\z/D', $value) || $value === "." || $value === "..") throw new RouteError("Choices must be literal URL-safe segments");
                    }
                    $expression = "(?:" . \implode("|", \array_map(static fn($value) => \preg_quote($value, "~"), $values)) . ")";
                }
                self::constraint($expression);
                $ignored = \in_array($name, $unused, true);
                $parameters[$name] = ["name" => $name, "type" => $type, "capture" => "route_" . $name, "source" => "requestPath", "optional" => $isOptional, "unused" => $ignored, "expression" => $expression];
                $part .= "(?<route_" . $name . ">" . $expression . ")";
                $namePart .= $ignored && isset($choices[$name]) && $match[0][0] === $segment ? "_" : "[" . $name . "]";
                $canonicalPart .= "{" . $name . ":" . $type . ($isOptional ? "?" : "") . "}";
                $offset = $match[0][1] + \strlen($match[0][0]);
            }
            $literal = \substr($segment, $offset);
            self::literal($literal);
            if ($segment === "." || $segment === ".." || $segment === "") throw new RouteError("Invalid route segment");
            $part .= \preg_quote($literal, "~");
            $namePart .= $literal;
            $canonicalPart .= $literal;
            $pattern .= $optional ? "(?:/" . $part . ")?" : "/" . $part;
            $filename[] = $namePart;
            $canonical[] = $canonicalPart;
        }
        foreach (\array_merge(\array_keys($constraints), \array_keys($choices), $unused) as $name) {
            if (!isset($parameters[$name])) throw new RouteError("Unknown route parameter: " . $name);
        }
        if (\in_array($filename[0] ?? "", ["GET", "HEAD", "POST", "PUT", "PATCH", "DELETE", "OPTIONS", "assets"], true)) throw new RouteError("This path is reserved for implementation files or assets");
        return ["path" => $canonical ? "/" . \implode("/", $canonical) : "/", "pattern" => $parameters ? "^" . ($pattern ?: "/") . '$' : $path, "parameters" => \array_values($parameters), "modifier" => $parameters ? "~" : "=", "filename" => $filename ? \implode("/", $filename) . ".php" : "index.php", "schema" => ["path" => $path, "options" => \array_intersect_key($options, \array_flip(["--constraint", "--choices", "--unused", "--named"]))]];
    }

    private static function literal(string $literal): void
    {
        if ($literal !== "" && !\preg_match('/\A[A-Za-z0-9._~-]+\z/D', $literal)) throw new RouteError("Use literal URL-safe segments or named parameters");
    }

    private static function assignments(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $pair = \explode("=", $value, 2);
            if (\count($pair) !== 2 || isset($result[$pair[0]])) throw new RouteError("Use unique NAME=VALUE assignments");
            $result[$pair[0]] = $pair[1];
        }
        return $result;
    }

    private static function constraint(string $expression): void
    {
        // No capturing groups, server tokens, delimiters or assertions in parameter constraints.
        if (\preg_match('/[\s"\x27;#$~]/', $expression) || \preg_match('/(?<!\\\\)\((?!\?:)/', $expression) || @\preg_match("~\\A(?:" . $expression . ")\\z~D", "") === false) throw new RouteError("Use a parameter regex with noncapturing groups and no server directives");
    }

    public static function fromLocation(array $args): array
    {
        if (\count($args) !== 3 || $args[0] !== "location" || !\in_array($args[1], ["=", "~"], true)) {
            throw new RouteError("Unsupported location: use an exact path or an anchored typed route");
        }
        if ($args[1] === "=") {
            $description = self::describe($args[2]);
            if ($description["parameters"]) {
                throw new RouteError("Exact Nginx locations cannot contain typed captures");
            }
            return $description;
        }
        $pattern = $args[2];
        $path = $pattern;
        $captures = [];
        foreach (self::TYPES as $type => $expression) {
            $path = \preg_replace_callback("~\\(\\?<([a-z][A-Za-z0-9_]*)>" . \preg_quote($expression, "~") . "\\)~", static function ($match) use ($type, &$captures) {
                $name = \preg_replace("/^route_/", "", $match[1]);
                $captures[$name] = $match[1];
                return "{" . $name . ":" . $type . "}";
            }, $path);
        }
        if (!\str_starts_with($path, "^") || !\str_ends_with($path, '$')) {
            throw new RouteError("Typed routes must be anchored");
        }
        $description = self::describe(\substr($path, 1, -1));
        if (!$description["parameters"]) {
            throw new RouteError("Unrecognized route expression");
        }
        foreach ($description["parameters"] as &$parameter) {
            $parameter["capture"] = $captures[$parameter["name"]];
        }
        unset($parameter);
        $description["pattern"] = $pattern;
        return $description;
    }

    /** Derive an implementation path from one explicit method and a validated public path. */
    public static function inferEndpoint(string $path, string $method, array $options = []): string
    {
        $description = self::describe($path, $options);
        self::method($method);
        foreach ($description["parameters"] as $parameter) {
            if (\str_contains($parameter["name"], "_")) throw new RouteError("New endpoint parameters use lowerCamelCase names");
        }
        if ($description["path"] === "/index") throw new RouteError("/index requires an explicit alias; index.php is reserved for /");
        return "web/" . ($method === "HEAD" ? "GET" : $method) . "/" . $description["filename"];
    }

    public static function endpoint(string $endpoint): void
    {
        if (!\str_starts_with($endpoint, "web/") || !\str_ends_with($endpoint, ".php")) throw new RouteError("Select a .php file under web/, without traversal");
        foreach (\explode("/", $endpoint) as $segment) {
            if ($segment === "" || $segment === "." || $segment === ".." || !\preg_match('/\A(?:[A-Za-z0-9._~-]|\[[a-z][A-Za-z0-9_]*\])+\z/D', $segment)) throw new RouteError("Select a literal .php path under web/, without traversal");
        }
    }

    public static function method(string $method): void
    {
        if (!\preg_match("/\\A[A-Z_-]+\\z/D", $method) || \in_array($method, ["TRACE", "CONNECT"], true)) {
            throw new RouteError("This Nginx FastCGI profile accepts uppercase method names with hyphens/underscores, including custom methods; TRACE and CONNECT require a different server profile");
        }
    }
}
