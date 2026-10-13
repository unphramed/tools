<?php

declare(strict_types=1);

namespace Unphramed\Tools;

/** Interpretation of the documented native profiles, never a runtime registry. */
final class NginxRoutes
{
    public static function read(string $root, ?string $source = null, array $plannedEndpoints = []): array
    {
        $source ??= \file_get_contents($root . '/config/nginx.conf');
        $file = $root . '/config/nginx.conf';
        $result = ['routes' => [], 'locations' => [], 'diagnostics' => [], 'checks' => []];
        try {
            $nodes = (new NginxSyntax($source))->parse();
            $utilities = [];
            foreach ($nodes as $node) {
                try {
                    if ($kind = self::utility($node)) {
                        if (isset($utilities[$kind])) throw new RouteError('Duplicate utility location: ' . $kind);
                        $utilities[$kind] = true;
                    } elseif ($node['args'][0] === 'rewrite') {
                        if (!isset($utilities['php-protection'])) throw new RouteError('Place direct-script protection before server rewrites');
                        $result['locations'][] = RewriteRoutes::read($root, $node, $plannedEndpoints);
                    } elseif ($node['args'][0] === 'location') {
                        $result['locations'][] = self::location($node, $result);
                    } else {
                        throw new RouteError('Expected application locations included inside the environment server block. Migrate former maps/server or http.conf/routes.conf layouts manually; deployment settings and FastCGI transport belong to the environment.');
                    }
                } catch (RouteError $error) {
                    $result['diagnostics'][] = ['file' => $file, 'line' => $node['line'], 'message' => $error->getMessage()];
                }
            }
            foreach (['php-protection', 'fallback'] as $kind) {
                if (!isset($utilities[$kind])) throw new RouteError('Missing utility location: ' . $kind);
            }
        } catch (RouteError $error) {
            $result['diagnostics'][] = ['file' => $file, 'line' => $error->sourceLine, 'message' => $error->getMessage()];
        }
        foreach ($result['checks'] as &$check) $check['file'] = $file;
        unset($check);
        foreach ($result['locations'] as $location) {
            if ($location['kind'] === 'redirect') {
                $result['routes'][] = ['file' => $root . '/config/nginx.conf', 'line' => $location['node']['line'], 'offset' => $location['node']['start'], 'kind' => 'redirect', 'path' => $location['path'], 'methods' => [], 'endpoint' => null, 'parameters' => [], 'parameter' => null, 'redirect' => $location['redirect']];
                continue;
            }
            $groups = [];
            foreach ($location['bindings'] as $method => $endpoint) {
                $groups[$endpoint][] = $method;
            }
            foreach ($groups as $endpoint => $methods) {
                $result['routes'][] = ['file' => $root . '/config/nginx.conf', 'line' => $location['node']['line'], 'offset' => $location['node']['start'], 'kind' => 'endpoint', 'path' => $location['path'], 'methods' => $methods, 'endpoint' => $endpoint, 'parameters' => $location['parameters'], 'parameter' => $location['parameters'][0]['name'] ?? null];
            }
        }
        return $result;
    }

    /** Only these exact utility shapes are excluded from the endpoint inventory. */
    private static function utility(array $node): ?string
    {
        $return404 = [['return', '404'], null];
        $children = $node['children'] === null ? null : NginxSyntax::shape($node['children']);
        if ($children === [$return404]) {
            if ($node['args'] === ['if', '($uri', '~', '^/(?:GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS)(?:/|$)', ')']) return 'method-protection';
            if ($node['args'] === ['if', '($uri', '~', '\\.php(?:/|$)', ')']) return 'php-protection';
            if ($node['args'] === ['location', '/']) return 'fallback';
        }
        if ($node['args'] === ['location', '/assets/'] && $children === [[['try_files', '$uri', '=404'], null]]) return 'assets';
        return null;
    }

    private static function location(array $node, array &$result): array
    {
        $description = RoutePath::fromLocation($node['args']);
        if ($node['children'] === null) throw new RouteError('Location needs a block');
        if (\count($node['children']) === 1 && $node['children'][0]['args'][0] === 'return') {
            $return = $node['children'][0]['args'];
            if (\count($return) !== 3 || !\in_array($return[1], ['301', '302', '303', '307', '308'], true) || !\preg_match('~\A/[A-Za-z0-9/_-]*\z~D', $return[2])) {
                throw new RouteError('Only literal local redirects are interpreted');
            }
            return $description + ['node' => $node, 'kind' => 'redirect', 'redirect' => ['status' => (int) $return[1], 'target' => $return[2]]];
        }
        $allow = null;
        $rewrite = null;
        $guard = null;
        $variable = null;
        $bindings = [];
        foreach ($node['children'] as $child) {
            $args = $child['args'];
            if ($args[0] === 'if' && \count($args) === 4 && \str_ends_with($args[3], ')')) {
                $args[3] = \substr($args[3], 0, -1);
                $args[] = ')';
            }
            if (\count($args) === 4 && \array_slice($args, 0, 2) === ['add_header', 'Allow'] && $args[3] === 'always' && $allow === null && $child['children'] === null) {
                $allow = $args[2];
            } elseif ($args[0] === 'set' && \count($args) === 3 && $args[2] === '' && $variable === null && $guard === null && $rewrite === null && $child['children'] === null && \preg_match('/\A\$[a-z][a-z0-9_]*\z/D', $args[1])) {
                $variable = $args[1];
            } elseif ($args[0] === 'if' && \count($args) === 5 && $args[4] === ')' && $child['children'] !== null && $guard === null && $rewrite === null) {
                $shape = NginxSyntax::shape($child['children']);
                if ($args[1] === '($request_method' && $args[2] === '=' && $variable !== null && \count($shape) === 1 && \count($shape[0][0]) === 3 && \array_slice($shape[0][0], 0, 2) === ['set', $variable] && $shape[0][1] === null) {
                    RoutePath::method($args[3]);
                    if (isset($bindings[$args[3]])) throw new RouteError('Duplicate method association');
                    $endpoint = 'web' . $shape[0][0][2];
                    RoutePath::endpoint($endpoint);
                    $bindings[$args[3]] = $endpoint;
                } elseif ($shape === [[['return', '405'], null]]) {
                    if ($args[1] === '($request_method' && $args[2] === '!=' && $variable === null) {
                        RoutePath::method($args[3]);
                        $guard = [$args[3]];
                    } elseif ($args[1] === '($request_method' && $args[2] === '!~' && $variable === null && \preg_match('/\A\^\(\?:([A-Z_|-]+)\)\$\z/D', $args[3], $match)) {
                        $guard = \explode('|', $match[1]);
                        foreach ($guard as $method) RoutePath::method($method);
                        if (\count(\array_unique($guard)) !== \count($guard)) throw new RouteError('Duplicate guard method');
                    } elseif ($args[1] === '(' . $variable && $variable !== null && $args[2] === '=' && $args[3] === '' && $bindings) {
                        $guard = \array_keys($bindings);
                    } else {
                        throw new RouteError('Unsupported method guard');
                    }
                } else {
                    throw new RouteError('Only method selection and a return-only 405 guard are interpreted');
                }
            } elseif ($args[0] === 'rewrite' && \count($args) === 4 && $args[1] === '^' && $args[3] === 'last' && $rewrite === null && $guard !== null && $child['children'] === null) {
                $rewrite = $args[2];
            } else {
                throw new RouteError('Unsupported location directive or ordering: ' . $args[0]);
            }
        }
        if ($guard === null || $rewrite === null) throw new RouteError('Expected an explicit method guard and rewrite with last to the environment PHP location');
        if ($variable !== null) {
            if ($rewrite !== $variable) throw new RouteError('Rewrite must use the selected endpoint variable');
        } else {
            $endpoint = 'web' . $rewrite;
            RoutePath::endpoint($endpoint);
            foreach ($guard as $method) $bindings[$method] = $endpoint;
        }
        $allowed = $allow === null ? [] : \array_map('trim', \explode(',', $allow));
        $methods = \array_keys($bindings);
        \sort($allowed); \sort($methods);
        if ($allowed !== $methods) $result['checks'][] = ['file' => '', 'line' => $node['line'], 'message' => 'Allow must list exactly the selected methods: ' . \implode(', ', \array_keys($bindings))];
        return $description + ['node' => $node, 'kind' => 'endpoint', 'bindings' => $bindings];
    }

}
