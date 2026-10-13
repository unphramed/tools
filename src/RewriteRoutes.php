<?php

declare(strict_types=1);

namespace Unphramed\Tools;

/** Development-time interpretation of server-level rewrites, never an HTTP dispatcher. */
final class RewriteRoutes
{
    public static function read(string $root, array $node, array $plannedEndpoints = []): array
    {
        $args = $node['args'];
        if ($node['children'] !== null || count($args) !== 4) throw new RouteError('Expected a native rewrite directive');
        [$directive, $pattern, $target, $flag] = $args;
        if (in_array($flag, ['permanent', 'redirect'], true)) {
            $description = self::description($pattern, []);
            if ($description['parameters'] || !preg_match('~\A/[A-Za-z0-9/_-]*\z~D', $target)) throw new RouteError('Only literal local rewrite redirects are supported');
            return $description + ['node' => $node, 'kind' => 'redirect', 'profile' => 'rewrite', 'redirect' => ['status' => $flag === 'permanent' ? 301 : 302, 'target' => $target]];
        }
        if ($flag !== 'last') throw new RouteError('Application rewrites must use last to reach the common PHP handler');
        $parts = explode('?', $target, 2);
        $script = $parts[0];
        $arguments = [];
        if (isset($parts[1])) {
            if (!str_starts_with($parts[1], '$args&') || !str_ends_with($parts[1], '?')) throw new RouteError('Place original $args first and terminate the replacement with ?');
            foreach (explode('&', substr($parts[1], 6, -1)) as $pair) {
                if (!preg_match('/\A([a-z][a-z0-9_]*)=\$([1-9])\z/D', $pair, $match) || isset($arguments[(int) $match[2]]) || in_array($match[1], $arguments, true)) {
                    throw new RouteError('Use unique query names bound to positional captures');
                }
                $arguments[(int) $match[2]] = $match[1];
            }
        }
        $description = self::description($pattern, $arguments);
        $dynamic = str_starts_with($script, '/$request_method/');
        $endpointPattern = 'web' . $script;
        RoutePath::endpoint(str_replace('$request_method', 'GET', $endpointPattern));
        $bindings = $dynamic ? self::bindings($root, $endpointPattern, $plannedEndpoints) : ['*' => $endpointPattern];
        return $description + ['node' => $node, 'kind' => 'endpoint', 'profile' => 'rewrite', 'endpointPattern' => $endpointPattern, 'bindings' => $bindings];
    }

    public static function bindings(string $root, string $pattern, array $plannedEndpoints = []): array
    {
        $bindings = [];
        $methods = is_dir($root . '/web') ? scandir($root . '/web') : [];
        foreach ($plannedEndpoints as $file) {
            if (preg_match('~\Aweb/([A-Z_-]+)/~D', $file, $match)) $methods[] = $match[1];
        }
        $methods = array_unique($methods);
        sort($methods);
        foreach ($methods as $method) {
            if (!preg_match('/\A[A-Z_-]+\z/D', $method) || in_array($method, ['TRACE', 'CONNECT'], true)) continue;
            $endpoint = str_replace('$request_method', $method, $pattern);
            $file = RouteEditor::path($root, $endpoint);
            if (is_file($file) || in_array($endpoint, $plannedEndpoints, true)) $bindings[$method] = $endpoint;
        }
        return $bindings;
    }

    private static function description(string $pattern, array $arguments): array
    {
        if (!str_starts_with($pattern, '^') || !str_ends_with($pattern, '$')) throw new RouteError('Rewrite paths must be anchored');
        $path = substr($pattern, 1, -1);
        $parameters = [];
        $position = 0;
        // Only captures that remain safe when inserted into a query string are accepted.
        $expressions = ['int' => ['[1-9][0-9]{0,8}', '\d+'], 'slug' => [RoutePath::TYPES['slug']]];
        while (preg_match('/\(/', $path, $opening, PREG_OFFSET_CAPTURE)) {
            $start = $opening[0][1];
            $matched = false;
            foreach ($expressions as $type => $variants) {
                foreach ($variants as $expression) {
                    $capture = '(' . $expression . ')';
                    if (substr($path, $start, strlen($capture)) !== $capture) continue;
                    $position++;
                    $name = $arguments[$position] ?? null;
                    if ($name === null) throw new RouteError('Each rewrite capture needs one query argument');
                    $parameters[] = ['name' => $name, 'type' => $type, 'capture' => '$' . $position, 'source' => 'query', 'queryKey' => $name];
                    $path = substr($path, 0, $start) . '{' . $name . ':' . $type . '}' . substr($path, $start + strlen($capture));
                    $matched = true;
                    break 2;
                }
            }
            if (!$matched) throw new RouteError('Use numeric/slug rewrite captures; arbitrary strings need the optional location/request-path profile');
        }
        if (count($parameters) !== count($arguments)) throw new RouteError('Every query binding must reference one route capture');
        $description = RoutePath::describe($path);
        $description['parameters'] = $parameters;
        $description['pattern'] = $pattern;
        return $description;
    }

    public static function text(array $description, string $endpointPattern): string
    {
        $pattern = $description['pattern'];
        if (!str_starts_with($pattern, '^')) $pattern = '^' . $pattern . '$';
        foreach ($description['parameters'] as $parameter) {
            $pattern = str_replace('(?<' . $parameter['capture'] . '>', '(', $pattern);
            if ($parameter['type'] === 'string') throw new RouteError('Arbitrary string captures need the optional location/request-path profile');
        }
        $target = substr($endpointPattern, 3);
        if ($description['parameters']) {
            $target .= '?$args';
            foreach ($description['parameters'] as $index => $parameter) $target .= '&' . $parameter['name'] . '=$' . ($index + 1);
            $target .= '?';
        }
        return 'rewrite "' . $pattern . '" ' . $target . ' last;';
    }
}
