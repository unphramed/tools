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
        if (isset($options["--endpoint"]) && \count($options["--method"] ?? ["GET"]) === 1) {
            // An existing conventional filename uses the same rewrite profile as a new file.
            if (($options["--path"] ?? "") !== "/index" && $options["--endpoint"] === RoutePath::inferEndpoint($options["--path"] ?? "", ($options["--method"] ?? ["GET"])[0], $options)) unset($options["--endpoint"]);
        }
        if (!isset($options["--endpoint"])) {
            $description = RoutePath::describe($options["--path"] ?? "", $options);
            $endpoint = RoutePath::inferEndpoint($options["--path"] ?? "", ($options["--method"] ?? ["GET"])[0], $options);
            foreach ($inventory["routes"] as $route) {
                if ($route["endpoint"] === $endpoint && $route["path"] !== $description["path"]) throw new RouteError("Endpoint already belongs to another route; use an explicit alias or a different schema");
            }
        }
        $synchronized = \count($inventory["routingSources"]) === 2;
        if ($synchronized && isset($options["--endpoint"])) throw new RouteError("Synchronized additions use inferred method-directory endpoints; explicit targets belong to single-server profiles");
        $candidates = [];
        $changes = [];
        $dispatchInputs = [];
        $endpoints = [];
        foreach ($inventory["routingSources"] as $source) {
            $server = $source["server"];
            $proposal = $server === "nginx"
                ? self::nginxProposal($root, $options, $inventory["inventories"][$server], true)
                : ApacheRouteEditor::proposal($root, $options, $inventory["inventories"][$server]);
            $candidates[$server] = $proposal["source"];
            $before = \file_get_contents($source["file"]);
            if ($before !== $proposal["source"]) $changes[$source["file"]] = ["file" => $source["file"], "action" => "update", "beforeSha256" => \hash("sha256", $before), "text" => $proposal["source"]];
            $endpoints[] = $proposal["endpoint"];
            if ($proposal["create"]) {
                $destination = self::path($root, $proposal["endpoint"]);
                $changes[$destination] = ["file" => $destination, "action" => "create", "beforeSha256" => null, "text" => "<?php\n\ndeclare(strict_types=1);\n\n// TODO: validate HTTP input, authorize the action and invoke your application.\n// Read route inputs from \$_GET for rewrites, or REQUEST_URI in the optional location profile.\nheader(\"Content-Type: text/plain; charset=UTF-8\");\nhttp_response_code(501);\necho \"Not implemented\";\n"];
            }
            // Snapshot actual method files, excluding endpoints that this plan will create.
            $candidateInventory = $server === "nginx" ? NginxRoutes::read($root, $proposal["source"]) : ApacheRoutes::read($root, $proposal["source"]);
            foreach ($candidateInventory["locations"] as $location) {
                if ($location["kind"] === "endpoint" && isset($location["endpointPattern"]) && (\str_contains($location["endpointPattern"], '$request_method') || \str_contains($location["endpointPattern"], '$handler_method'))) {
                    $dispatchInputs[] = ["pattern" => $location["endpointPattern"], "bindings" => RewriteRoutes::bindings($root, $location["endpointPattern"])];
                }
            }
        }
        if ($synchronized && \count(\array_unique($endpoints)) !== 1) throw new RouteError("Server proposals disagree on the PHP endpoint");
        // Keep configuration updates first and create shared controllers only once.
        $changes = \array_values($changes);
        \usort($changes, static fn($a, $b) => ($a["action"] === "create") <=> ($b["action"] === "create"));
        $preconditions = [];
        foreach (\array_unique(\array_merge(["config/nginx.conf", "config/apache.conf", "bootstrap.php"], $endpoints, \array_column(\array_filter($inventory["routes"], static fn($route) => $route["kind"] === "endpoint"), "endpoint"))) as $relative) {
            $file = self::path($root, $relative);
            $preconditions[] = ["file" => $file, "sha256" => \is_file($file) ? \hash_file("sha256", $file) : null];
        }
        $plan = ["endpoint" => $endpoints[0], "routingSources" => $inventory["routingSources"], "changes" => $changes, "preconditions" => $preconditions, "dispatchInputs" => $dispatchInputs];
        $plan["planSha256"] = \hash("sha256", \json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $plan["inventory"] = RoutingSources::read($root, $candidates, \array_map(static fn($change) => \substr($change["file"], \strlen($root) + 1), \array_filter($changes, static fn($change) => $change["action"] === "create")));
        return $plan;
    }

    public static function nginxProposal(string $root, array $options, array $inventory, bool $replaceQuery): array
    {
        $description = RoutePath::describe($options["--path"] ?? "", $options);
        $methods = $options["--method"] ?? ["GET"];
        if (!$methods || \count(\array_unique($methods)) !== \count($methods)) {
            throw new RouteError("Supply unique --method values");
        }
        foreach ($methods as $method) RoutePath::method($method);
        if (!isset($options["--endpoint"]) && \count($methods) !== 1) {
            throw new RouteError("Choose one method for endpoint inference, or specify --endpoint to share a file explicitly.");
        }
        $endpoint = $options["--endpoint"] ?? RoutePath::inferEndpoint($options["--path"], $methods[0], $options);
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
            } elseif (self::descriptionsOverlap($location, $description)) {
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
        if ($selected !== null) {
            if (\array_intersect(["--constraint", "--choices", "--unused"], \array_keys($options)) && ($description["schema"]["options"] ?? []) !== ($selected["schema"]["options"] ?? [])) throw new RouteError("Parameter options differ from the existing route; edit its contract explicitly before adding a method");
            $description = $selected;
        }
        if ($selected === null && !isset($options["--endpoint"]) && (isset($bindings["GET"]) || isset($bindings["HEAD"]))) {
            $bindings["GET"] = $endpoint;
            $bindings["HEAD"] = $endpoint;
        }
        $rewriteProfile = ($selected["profile"] ?? "") === "rewrite";
        $suffixes = [];
        foreach ($bindings as $method => $file) {
            $selectionMethod = $method === "HEAD" && ($selected === null || \str_contains($selected["endpointPattern"] ?? "", '$handler_method')) ? "GET" : $method;
            if (\str_starts_with($file, "web/" . $selectionMethod . "/")) $suffixes[] = \substr($file, \strlen("web/" . $selectionMethod . "/"));
            else $suffixes[] = null;
        }
        $canRewrite = !\in_array(null, $suffixes, true) && \count(\array_unique($suffixes)) === 1 && !\array_filter($description["parameters"], static fn($parameter) => !RewriteRoutes::querySafe($parameter));
        if ($rewriteProfile && !$canRewrite) throw new RouteError("Extending a method-directory rewrite requires the same method-relative endpoint; use the optional location profile for other associations");
        if (($selected === null && $canRewrite && !isset($options["--endpoint"])) || $rewriteProfile) {
            $endpointPattern = $selected["endpointPattern"] ?? ('web/$handler_method/' . $suffixes[0]);
            $locationText = $rewriteProfile ? \substr(\file_get_contents(self::path($root, "config/nginx.conf")), $selected["node"]["start"], $selected["node"]["end"] - $selected["node"]["start"]) : RewriteRoutes::comment($description) . RewriteRoutes::text($description, $endpointPattern, $replaceQuery);
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
            if ($selected === null) $locationText = RewriteRoutes::comment($description) . $locationText;
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
        if ($selected === null && \str_contains($locationText, '$handler_method') && !\in_array("handler-method", $inventory["utilities"], true)) $candidate = 'set $handler_method $request_method;' . "\n" . 'if ($request_method = HEAD) { set $handler_method GET; }' . "\n" . $candidate;
        return ["source" => $candidate, "endpoint" => $endpoint, "create" => isset($options["--create-endpoint"])];
    }

    public static function descriptionsOverlap(array $left, array $right): bool
    {
        if (!$left["parameters"] || !$right["parameters"]) {
            $literal = !$left["parameters"] ? $left : $right;
            $other = !$left["parameters"] ? $right : $left;
            if (!$other["parameters"]) return $left["path"] === $right["path"];
            return (bool) \preg_match("~" . $other["pattern"] . "~D", $literal["path"]);
        }
        // Custom constraints cannot safely be compared by their default type.
        foreach ([$left, $right] as $description) foreach ($description["parameters"] as $parameter) {
            if (isset($parameter["expression"]) && $parameter["expression"] !== RoutePath::TYPES[$parameter["type"]]) {
                $left["path"] = \preg_replace('/:(?:int|slug)(\??)\}/', ':string$1}', $left["path"]);
                $right["path"] = \preg_replace('/:(?:int|slug)(\??)\}/', ':string$1}', $right["path"]);
            }
        }
        return self::overlap($left["path"], $right["path"]);
    }

    public static function overlap(string $left, string $right): bool
    {
        $variants = static function (string $path): array {
            $variants = [[]];
            foreach (\explode("/", \trim($path, "/")) as $segment) {
                $next = [];
                foreach ($variants as $parts) {
                    if (\str_ends_with($segment, "?}")) $next[] = $parts;
                    $next[] = [...$parts, \str_replace("?}", "}", $segment)];
                }
                $variants = $next;
                if (\count($variants) > 256) throw new RouteError("Too many optional route variants");
            }
            return $variants;
        };
        foreach ($variants($left) as $a) foreach ($variants($right) as $b) {
            if (\count($a) !== \count($b)) continue;
            $possible = true;
            foreach ($a as $index => $segment) {
                $other = $b[$index];
                if (!\str_contains($segment, "{") && !\str_contains($other, "{")) {
                    if ($segment !== $other) { $possible = false; break; }
                } elseif (!\str_contains($segment, "{") || !\str_contains($other, "{")) {
                    $schema = \str_contains($segment, "{") ? $segment : $other;
                    $literal = $schema === $segment ? $other : $segment;
                    $description = RoutePath::describe("/" . $schema);
                    if (!\preg_match("~" . $description["pattern"] . "~D", "/" . $literal)) { $possible = false; break; }
                }
            }
            if ($possible) return true;
        }
        return false;
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
