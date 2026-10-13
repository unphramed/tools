<?php

declare(strict_types=1);

namespace Unphramed\Tools;

use Throwable;

final class RouteEditor
{
    public static function path(string $root, string $relative): string
    {
        $path = $root;
        foreach (\explode("/", $relative) as $part) {
            if ($part === "" || $part === "." || $part === "..") {
                throw new RouteError("Invalid project-relative path");
            }
            $path .= "/" . $part;
            if (\is_link($path)) {
                throw new RouteError("Symlinks are not supported");
            }
        }
        return $path;
    }

    public static function preview(string $root, array $options, array $inventory): array
    {
        $description = RoutePath::describe($options["--path"] ?? "");
        $methods = $options["--method"] ?? ["GET"];
        if (!$methods || \count(\array_unique($methods)) !== \count($methods)) {
            throw new RouteError("Supply unique --method values");
        }
        foreach ($methods as $method) RoutePath::method($method);
        if (!isset($options["--endpoint"]) && \count($methods) !== 1) {
            throw new RouteError("Choose one method for endpoint inference, or specify --endpoint to share a file explicitly.");
        }
        $endpoint = $options["--endpoint"] ?? RoutePath::inferEndpoint($options["--path"], $methods[0]);
        RoutePath::endpoint($endpoint);
        $destination = self::path($root, $endpoint);
        if (isset($options["--create-endpoint"]) && \file_exists($destination)) {
            throw new RouteError("Endpoint already exists; creation never overwrites a file");
        }
        if (!\is_file($destination)) $options["--create-endpoint"] = true;
        $selected = null;
        foreach ($inventory["locations"] as $location) {
            if ($location["path"] === $description["path"]) {
                if ($location["kind"] !== "endpoint" || $selected !== null) {
                    throw new RouteError("Path already belongs to a redirect or competing locations");
                }
                $selected = $location;
            } elseif (self::overlap($location["path"], $description["path"])) {
                throw new RouteError("Potential route collision with " . $location["path"]);
            }
        }
        $bindings = $selected["bindings"] ?? [];
        foreach ($methods as $method) {
            if (isset($bindings[$method])) {
                throw new RouteError("Method already mapped for this path: " . $method);
            }
            $bindings[$method] = $endpoint;
        }
        if ($selected !== null) $description = $selected;
        $rewriteProfile = ($selected["profile"] ?? "") === "rewrite";
        $suffixes = [];
        foreach ($bindings as $method => $file) {
            if (\str_starts_with($file, "web/" . $method . "/")) $suffixes[] = \substr($file, \strlen("web/" . $method . "/"));
            else $suffixes[] = null;
        }
        $canRewrite = !\in_array(null, $suffixes, true) && \count(\array_unique($suffixes)) === 1 && !\in_array("string", \array_column($description["parameters"], "type"), true);
        if ($rewriteProfile && !$canRewrite) throw new RouteError("Extending a method-directory rewrite requires the same method-relative endpoint; use the optional location profile for other associations");
        if (($selected === null && $canRewrite && !isset($options["--endpoint"])) || $rewriteProfile) {
            $endpointPattern = 'web/$request_method/' . $suffixes[0];
            $locationText = $rewriteProfile ? \substr(\file_get_contents(self::path($root, "config/nginx.conf")), $selected["node"]["start"], $selected["node"]["end"] - $selected["node"]["start"]) : RewriteRoutes::text($description, $endpointPattern);
        } else {
            $pattern = $description["modifier"] === "~" ? '"' . $description["pattern"] . '"' : $description["pattern"];
            $allowText = \implode(", ", \array_keys($bindings));
            $locationText = "location {$description["modifier"]} $pattern {\n    add_header Allow \"$allowText\" always;\n";
            if (\count(\array_unique($bindings)) === 1) {
                $methodNames = \array_keys($bindings);
                $guard = \count($methodNames) === 1 ? "!= " . $methodNames[0] : '!~ "^(?:' . \implode("|", $methodNames) . ')$"';
                $locationText .= "    if (\$request_method $guard) { return 405; }\n";
                $target = \substr(\reset($bindings), 3);
            } else {
                $target = '$unphramed_' . \substr(\hash("sha256", $description["path"]), 0, 12) . "_endpoint";
                $locationText .= "    set $target \"\";\n";
                foreach ($bindings as $method => $file) {
                    $file = \substr($file, 3);
                    $locationText .= "    if (\$request_method = $method) { set $target $file; }\n";
                }
                $locationText .= "    if ($target = \"\") { return 405; }\n";
            }
            $locationText .= "    rewrite ^ $target last;\n";
            $locationText .= "}";
        }
        $routeFile = self::path($root, "config/nginx.conf");
        $source = \file_get_contents($routeFile);
        if ($selected === null) {
            $candidate = $source . (\str_ends_with($source, "\n") ? "\n" : "\n\n") . "$locationText\n";
        } else {
            $node = $selected["node"];
            $old = \substr($source, $node["start"], $node["end"] - $node["start"]);
            $comments = $rewriteProfile ? [] : (new NginxSyntax($old))->comments();
            $locationText = \implode("\n", $comments) . ($comments ? "\n" : "") . $locationText;
            $candidate = \substr($source, 0, $node["start"]) . $locationText . \substr($source, $node["end"]);
        }
        $changes = $candidate === $source ? [] : [
            ["file" => $routeFile, "action" => "update", "beforeSha256" => \hash("sha256", $source), "text" => $candidate],
        ];
        if (isset($options["--create-endpoint"])) {
            $changes[] = ["file" => $destination, "action" => "create", "beforeSha256" => null, "text" => "<?php\n\ndeclare(strict_types=1);\n\n// TODO: validate HTTP input, authorize the action and invoke your application.\n// Read route inputs from \$_GET for rewrites, or REQUEST_URI in the optional location profile.\nheader(\"Content-Type: text/plain; charset=UTF-8\");\nhttp_response_code(501);\necho \"Not implemented\";\n"];
        }
        $preconditions = [];
        foreach (\array_unique(\array_merge(["config/nginx.conf", "bootstrap.php", $endpoint], \array_column(\array_filter($inventory["routes"], static fn($route) => $route["kind"] === "endpoint"), "endpoint"))) as $relative) {
            $file = self::path($root, $relative);
            $preconditions[] = ["file" => $file, "sha256" => \is_file($file) ? \hash_file("sha256", $file) : null];
        }
        $dispatchInputs = \array_values(\array_map(static fn($location) => ["pattern" => $location["endpointPattern"], "bindings" => $location["bindings"]], \array_filter(NginxRoutes::read($root, $candidate)["locations"], static fn($location) => ($location["profile"] ?? "") === "rewrite" && $location["kind"] === "endpoint" && \str_contains($location["endpointPattern"], '$request_method'))));
        $plan = ["changes" => $changes, "preconditions" => $preconditions, "dispatchInputs" => $dispatchInputs];
        $plan["planSha256"] = \hash("sha256", \json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $plan["inventory"] = NginxRoutes::read($root, $candidate, \array_map(static fn($change) => \substr($change["file"], \strlen($root) + 1), \array_filter($changes, static fn($change) => $change["action"] === "create")));
        return $plan;
    }

    private static function overlap(string $left, string $right): bool
    {
        $left = \explode("/", $left);
        $right = \explode("/", $right);
        if (\count($left) !== \count($right)) {
            return false;
        }
        foreach ($left as $index => $part) {
            $other = $right[$index];
            $a = \preg_match("/\\A\\{[^:}]+:(int|slug|string)\\}\\z/D", $part, $matchA);
            $b = \preg_match("/\\A\\{[^:}]+:(int|slug|string)\\}\\z/D", $other, $matchB);
            if ($a && $b) {
                continue;
            }
            if ($a || $b) {
                if (!\preg_match("~\\A" . RoutePath::TYPES[$a ? $matchA[1] : $matchB[1]] . "\\z~D", $a ? $other : $part)) {
                    return false;
                }
            } elseif ($part !== $other) {
                return false;
            }
        }
        return true;
    }

    public static function apply(string $root, array $plan): void
    {
        $locks = [];
        $updateHandles = [];
        $written = [];
        $created = [];
        $directories = [];
        try {
            $preconditions = $plan["preconditions"];
            \usort($preconditions, static fn($a, $b) => \strcmp($a["file"], $b["file"]));
            foreach ($preconditions as $condition) {
                self::path($root, \substr($condition["file"], \strlen($root) + 1));
                if ($condition["sha256"] !== null) {
                    $updating = \in_array($condition["file"], \array_column($plan["changes"], "file"), true);
                    $handle = \fopen($condition["file"], $updating ? "r+" : "r");
                    if ($handle === false || !\flock($handle, LOCK_EX)) {
                        throw new RouteError("Cannot lock preview input");
                    }
                    $locks[] = $handle;
                    if ($updating) {
                        $updateHandles[$condition["file"]] = $handle;
                    }
                }
            }
            foreach ($preconditions as $condition) {
                $actual = \is_file($condition["file"]) ? \hash_file("sha256", $condition["file"]) : null;
                if ($actual !== $condition["sha256"] || ($actual === null && \file_exists($condition["file"]))) {
                    throw new RouteError("Preview is stale; preview again");
                }
            }
            foreach ($plan["dispatchInputs"] as $input) {
                if (RewriteRoutes::bindings($root, $input["pattern"]) !== $input["bindings"]) throw new RouteError("Preview is stale: available method endpoints changed; preview again");
            }
            foreach ($plan["changes"] as $change) {
                $file = $change["file"];
                if ($change["action"] === "create") {
                    $missing = [];
                    for ($parent = \dirname($file); !\is_dir($parent); $parent = \dirname($parent)) {
                        $missing[] = $parent;
                    }
                    foreach (\array_reverse($missing) as $directory) {
                        if (!\mkdir($directory)) {
                            throw new RouteError("Cannot create endpoint directory");
                        }
                        $directories[] = $directory;
                    }
                    $handle = @\fopen($file, "x");
                    if ($handle === false) {
                        throw new RouteError("Cannot exclusively create endpoint");
                    }
                    $created[] = $file;
                } else {
                    $written[$file] = \file_get_contents($file);
                    $handle = $updateHandles[$file];
                    if (!\rewind($handle) || !\ftruncate($handle, 0)) {
                        throw new RouteError("Cannot update configuration file");
                    }
                }
                try {
                    if (\fwrite($handle, $change["text"]) !== \strlen($change["text"]) || !\fflush($handle)) {
                        throw new RouteError("Incomplete file write");
                    }
                } finally {
                    if ($change["action"] === "create") {
                        \fclose($handle);
                    }
                }
            }
        } catch (Throwable $error) {
            foreach ($written as $file => $before) {
                $handle = $updateHandles[$file];
                \rewind($handle);
                \ftruncate($handle, 0);
                if (\fwrite($handle, $before) !== \strlen($before) || !\fflush($handle)) {
                    throw new RouteError("Rollback failed; recover configuration through application version control");
                }
            }
            foreach ($created as $file) {
                \unlink($file);
            }
            foreach (\array_reverse($directories) as $directory) {
                \rmdir($directory);
            }
            throw $error;
        } finally {
            foreach ($locks as $handle) {
                \flock($handle, LOCK_UN); \fclose($handle);
            }
        }
    }
}
