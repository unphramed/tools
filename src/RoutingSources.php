<?php

declare(strict_types=1);

namespace Unphramed\Tools;

/** Detect application-owned sources and compare the portable routing contract. */
final class RoutingSources
{
    public static function read(string $root, array $candidates = [], array $plannedEndpoints = [], bool $readOnly = false): array
    {
        $result = ["routes" => [], "diagnostics" => [], "checks" => [], "routingSources" => [], "inventories" => []];
        foreach (["nginx", "apache"] as $server) {
            $file = RouteEditor::path($root, "config/" . $server . ".conf");
            if (!\file_exists($file)) continue;
            if (!\is_file($file) || !\is_readable($file)) throw new RouteError("Routing source must be a readable file", 1, $file);
            $inventory = $server === "nginx" && $readOnly ? NativeRouteInventory::read($root, \file_get_contents($file)) : ($server === "nginx" ? NginxRoutes::read($root, $candidates[$server] ?? null, $plannedEndpoints) : ApacheRoutes::read($root, $candidates[$server] ?? null, $plannedEndpoints));
            $result["routingSources"][] = ["server" => $server, "file" => $file];
            $result["inventories"][$server] = $inventory;
            foreach (["routes", "diagnostics", "checks"] as $key) $result[$key] = \array_merge($result[$key], $inventory[$key]);
        }
        if (!$result["routingSources"]) throw new RouteError("Missing readable config/nginx.conf or config/apache.conf. Supply an application routing fragment included by your environment; migrate former maps/server or http.conf/routes.conf layouts manually.");
        if (!$readOnly && \count($result["routingSources"]) === 2 && !$result["diagnostics"]) {
            try {
                self::compare($result["inventories"]);
            } catch (RouteError $error) {
                $result["checks"][] = ["file" => $root . "/config/apache.conf", "line" => $error->sourceLine, "message" => $error->getMessage()];
            }
        }
        return $result;
    }

    private static function compare(array $inventories): void
    {
        if (!$inventories["apache"]["sharedDispatch"] || \in_array("assets", $inventories["nginx"]["utilities"], true)) throw new RouteError("Synchronization requires the common method-directory/404 profile on both servers");
        $contracts = [];
        foreach ($inventories as $server => $inventory) {
            $contracts[$server] = [];
            foreach ($inventory["locations"] as $location) {
                if (($location["profile"] ?? "") !== "rewrite" || ($location["apacheDirect"] ?? false)) throw new RouteError("Synchronization requires the common rewrite/404 profile: " . $location["path"], $location["node"]["line"]);
                $contract = ["path" => $location["path"], "pattern" => $location["pattern"], "parameters" => $location["parameters"], "kind" => $location["kind"]];
                if ($location["kind"] === "redirect") {
                    $contract["redirect"] = $location["redirect"];
                } else {
                    if (!\str_contains($location["endpointPattern"], '$request_method') && !\str_contains($location["endpointPattern"], '$handler_method')) throw new RouteError("Synchronization supports method-directory endpoints only: " . $location["path"], $location["node"]["line"]);
                    $bindings = $location["bindings"];
                    \ksort($bindings);
                    $contract += ["target" => $location["endpointPattern"], "bindings" => $bindings, "parameters" => $location["parameters"], "queryMode" => $location["queryMode"]];
                }
                // Refuse ambiguous ordering rather than claiming two route tables are equivalent.
                foreach ($contracts[$server] as $path => $previous) {
                    if (RouteEditor::descriptionsOverlap($previous, $location)) throw new RouteError("Overlapping routes prevent synchronization: " . $location["path"], $location["node"]["line"]);
                }
                $contracts[$server][$location["path"]] = $contract;
            }
        }
        foreach (\array_unique(\array_merge(\array_keys($contracts["nginx"]), \array_keys($contracts["apache"]))) as $path) {
            if (($contracts["nginx"][$path] ?? null) !== ($contracts["apache"][$path] ?? null)) throw new RouteError("Nginx/Apache divergence at " . $path . ": compare methods, target, captures, query policy and redirect; resolve it manually before adding routes");
        }
    }
}
