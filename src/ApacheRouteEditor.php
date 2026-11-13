<?php

declare(strict_types=1);

namespace Unphramed\Tools;

final class ApacheRouteEditor
{
    public static function proposal(string $root, array $options, array $inventory): array
    {
        $description = RoutePath::describe($options["--path"] ?? "", $options);

        if (\array_filter($description["parameters"], static fn($parameter) => !RewriteRoutes::querySafe($parameter))) {
            throw new RouteError("Apache additions support numeric and slug parameters only");
        }

        $methods = $options["--method"] ?? ["GET"];

        foreach ($methods as $method) {
            RoutePath::method($method);
        }

        if (!$methods || \count($methods) !== \count(\array_unique($methods))) {
            throw new RouteError("Supply unique --method values");
        }

        if (!isset($options["--endpoint"]) && \count($methods) !== 1) {
            throw new RouteError("Choose one method for inference or an explicit shared endpoint");
        }

        $endpoint = $options["--endpoint"] ?? RoutePath::inferEndpoint($options["--path"], $methods[0], $options);
        RoutePath::endpoint($endpoint);
        $destination = RouteEditor::path($root, $endpoint);

        if (isset($options["--create-endpoint"]) && \file_exists($destination)) {
            throw new RouteError("Endpoint already exists; creation never overwrites a file");
        }

        $selected = [];

        foreach ($inventory["locations"] as $location) {
            if ($location["path"] === $description["path"]) {
                if ($location["kind"] !== "endpoint") {
                    throw new RouteError("Path already belongs to a redirect");
                }

                if (isset($location["bindings"]["*"]) || \array_intersect($methods, \array_keys($location["bindings"]))) {
                    throw new RouteError("Method already mapped for this path");
                }

                if (\array_intersect(["--constraint", "--choices", "--unused"], \array_keys($options)) && ($description["schema"]["options"] ?? []) !== ($location["schema"]["options"] ?? [])) {
                    throw new RouteError("Parameter options differ from the existing route; edit its contract explicitly before adding a method");
                }

                $selected[] = $location;
            } elseif (RouteEditor::descriptionsOverlap($location, $description)) {
                throw new RouteError("Potential route collision with " . $location["path"]);
            }
        }

        $source = \file_get_contents($root . "/config/apache.conf");

        foreach ($selected as $location) {
            if ((\str_contains($location["endpointPattern"], '$request_method') || \str_contains($location["endpointPattern"], '$handler_method'))) {
                foreach ($methods as $method) {
                    if (\str_replace(['$request_method', '$handler_method'], $method === "HEAD" && \str_contains($location["endpointPattern"], '$handler_method') ? "GET" : $method, $location["endpointPattern"]) !== $endpoint) {
                        throw new RouteError("Extending a method-directory rewrite requires the same method-relative endpoint");
                    }
                }

                return ["source" => $source, "endpoint" => $endpoint, "create" => !\is_file($destination)];
            }
        }

        if ($selected) {
            // Preserve the existing capture spelling and query names when extending fixed targets.
            $description = $selected[0];

            foreach ($selected as $location) {
                if ($location["pattern"] !== $description["pattern"] || $location["parameters"] !== $description["parameters"]) {
                    throw new RouteError("Competing route patterns cannot be extended safely");
                }
            }
        }

        $dynamic = !isset($options["--endpoint"]) && !$selected;
        $pattern = $dynamic ? \str_replace("web/" . ($methods[0] === "HEAD" ? "GET" : $methods[0]) . "/", 'web/$handler_method/', $endpoint) : $endpoint;
        $nginx = (new NginxSyntax(RewriteRoutes::text($description, $pattern, true)))->parse()[0]["args"];
        $target = \str_replace(['$request_method', '$handler_method'], ["%{REQUEST_METHOD}", "%{ENV:handler_method}"], $nginx[2]);
        $captures = [];

        foreach ($description["parameters"] as $index => $parameter) {
            $captures['$' . $parameter["capture"]] = '$' . ($index + 1);
        }

        $target = \strtr($target, $captures);

        if (\str_contains($target, "?")) {
            $target = \substr($target, 0, -1);
        }

        $text = "";

        if (!$dynamic) {
            $text .= "RewriteCond %{REQUEST_METHOD} ^(?:" . \implode("|", $methods) . ")$\n";
        }

        $text .= RewriteRoutes::comment($description) . 'RewriteRule "' . $nginx[1] . '" ' . $target . ($inventory["sharedDispatch"] ? "" : " [PT]") . "\n";

        if ($dynamic && !\str_contains($source, "[E=handler_method:%{REQUEST_METHOD}]")) {
            $text = "RewriteRule ^ - [E=handler_method:%{REQUEST_METHOD}]\nRewriteCond %{REQUEST_METHOD} ^HEAD$\nRewriteRule ^ - [E=handler_method:GET]\n" . $text;
        }

        $offset = $inventory["insertOffset"];
        $before = \substr($source, 0, $offset);
        $candidate = $before . (\str_ends_with($before, "\n") ? "" : "\n") . $text . \substr($source, $offset);
        return ["source" => $candidate, "endpoint" => $endpoint, "create" => !\is_file($destination)];
    }
}
