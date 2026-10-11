<?php

declare(strict_types=1);

namespace Unphramed\Tools;

/** Interpretation of the documented native profiles, never a runtime registry. */
final class NginxRoutes
{
    public static function read(string $root, ?string $source = null): array
    {
        $source ??= file_get_contents($root . '/config/nginx.conf');
        $file = $root . '/config/nginx.conf';
        $result = ['routes' => [], 'locations' => [], 'maps' => [], 'diagnostics' => [], 'checks' => [], 'server' => null, 'environmentInclude' => null];
        try {
            $nodes = (new NginxSyntax($source))->parse();
            foreach ($nodes as $node) {
                try {
                    if ($node['args'][0] === 'map') {
                        if ($result['server'] !== null) throw new RouteError('Place maps before the server block');
                        self::map($node, $result);
                    } elseif ($node['args'] === ['server'] && $node['children'] !== null && $result['server'] === null) {
                        $result['server'] = $node;
                    } else {
                        if (in_array($node['args'][0], ['http', 'events', 'pid', 'worker_processes', 'error_log'], true)) {
                            throw new RouteError('This is a deployment envelope, not the application routing file. Migrate the former http.conf/routes.conf layout using the HTTP routing migration guide; keep the global Nginx envelope separate.');
                        }
                        throw new RouteError('Expected method/status maps and exactly one application server block');
                    }
                } catch (RouteError $error) {
                    $result['diagnostics'][] = ['file' => $file, 'line' => $node['line'], 'message' => $error->getMessage()];
                }
            }
            if ($result['server'] === null) throw new RouteError('Missing application server block');
            $utilities = [];
            foreach ($result['server']['children'] as $node) {
                try {
                    if ($node['args'][0] === 'include' && $node['children'] === null && count($node['args']) === 2) {
                        if ($result['environmentInclude'] !== null || !preg_match('/\A[a-zA-Z0-9_-]+-server\.conf\z/D', $node['args'][1])) {
                            throw new RouteError('Use one application-named environment include, for example read-only-server.conf');
                        }
                        $result['environmentInclude'] = $node['args'][1];
                    } elseif ($kind = self::utility($node)) {
                        if (isset($utilities[$kind])) throw new RouteError('Duplicate utility location: ' . $kind);
                        $utilities[$kind] = true;
                    } elseif ($node['args'][0] === 'location') {
                        if (($node['args'][1] ?? '') === '~' && (!isset($utilities['method-protection']) || !isset($utilities['php-protection']))) {
                            throw new RouteError('Place implementation-URL protection before parameter routes');
                        }
                        $result['locations'][] = self::location($node, $result, $root);
                    } else {
                        throw new RouteError('Unsupported application directive: ' . $node['args'][0] . '. Put deployment settings in the environment fragment.');
                    }
                } catch (RouteError $error) {
                    $result['diagnostics'][] = ['file' => $file, 'line' => $node['line'], 'message' => $error->getMessage()];
                }
            }
            if ($result['environmentInclude'] === null) throw new RouteError('Missing application-named environment include');
            foreach (['method-protection', 'php-protection', 'fallback'] as $kind) {
                if (!isset($utilities[$kind])) throw new RouteError('Missing utility location: ' . $kind);
            }
        } catch (RouteError $error) {
            $result['diagnostics'][] = ['file' => $file, 'line' => $error->sourceLine, 'message' => $error->getMessage()];
        }
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
            if ($node['args'] === ['location', '~', '^/(?:GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS)(?:/|$)']) return 'method-protection';
            if ($node['args'] === ['location', '~', '\\.php(?:/|$)']) return 'php-protection';
            if ($node['args'] === ['location', '/']) return 'fallback';
        }
        if ($node['args'] === ['location', '/assets/'] && $children === [[['try_files', '$uri', '=404'], null]]) return 'assets';
        return null;
    }

    private static function map(array $node, array &$result): void
    {
        $args = $node['args'];
        if (count($args) !== 3 || $args[0] !== 'map' || !in_array($args[1], ['$request_method', '$status'], true) || !preg_match('/\A\$[a-z][a-z0-9_]*\z/D', $args[2]) || $node['children'] === null) {
            throw new RouteError('Unsupported HTTP configuration: expected a literal method or status map');
        }
        if (isset($result['maps'][$args[2]])) {
            throw new RouteError('Duplicate map variable: ' . $args[2]);
        }
        $entries = [];
        foreach ($node['children'] as $child) {
            if (count($child['args']) !== 2 || $child['children'] !== null || isset($entries[$child['args'][0]])) {
                throw new RouteError('Map needs unique literal entries');
            }
            [$key, $value] = $child['args'];
            if ($key !== 'default' && $args[1] === '$request_method') {
                RoutePath::method($key);
            }
            if ($key !== 'default' && $args[1] === '$status' && $key !== '405') {
                throw new RouteError('Only the documented 405 Allow status map is interpreted');
            }
            $entries[$key] = $value;
        }
        if (!array_key_exists('default', $entries) || $entries['default'] !== '') {
            throw new RouteError('Method/Allow maps must have an empty default');
        }
        unset($entries['default']);
        $result['maps'][$args[2]] = ['source' => $args[1], 'entries' => $entries, 'node' => $node];
    }

    private static function location(array $node, array &$result, string $root): array
    {
        $description = RoutePath::fromLocation($node['args']);
        if ($node['children'] === null) {
            throw new RouteError('Location needs a block');
        }
        if (count($node['children']) === 1 && $node['children'][0]['args'][0] === 'return') {
            $return = $node['children'][0]['args'];
            if (count($return) !== 3 || !in_array($return[1], ['301', '302', '303', '307', '308'], true) || !preg_match('~\A/[A-Za-z0-9/_-]*\z~D', $return[2])) {
                throw new RouteError('Only literal local redirects are interpreted');
            }
            return $description + ['node' => $node, 'kind' => 'redirect', 'redirect' => ['status' => (int) $return[1], 'target' => $return[2]]];
        }
        $directives = [];
        $params = [];
        $allow = null;
        $variable = null;
        foreach ($node['children'] as $child) {
            $args = $child['args'];
            if ($args[0] === 'if') {
                if ($variable !== null || count($args) !== 5 || !preg_match('/\A\(\$([a-z][a-z0-9_]*)\z/D', $args[1], $match) || $args[2] !== '=' || $args[3] !== '' || $args[4] !== ')' || $child['children'] === null) {
                    throw new RouteError('Only an empty method-map guard is interpreted');
                }
                $variable = '$' . $match[1];
                $returns = 0;
                foreach ($child['children'] as $guard) {
                    if ($guard['args'] === ['return', '405'] && $guard['children'] === null) {
                        $returns++;
                    } elseif (count($guard['args']) === 4 && array_slice($guard['args'], 0, 2) === ['add_header', 'Allow'] && $guard['args'][3] === 'always' && $guard['children'] === null && $allow === null) {
                        $allow = $guard['args'][2];
                    } else {
                        throw new RouteError('Unsupported method guard');
                    }
                }
                if ($returns !== 1) {
                    throw new RouteError('Method guard must return 405');
                }
            } elseif ($child['children'] !== null) {
                throw new RouteError('Unsupported nested location block');
            } elseif ($args[0] === 'fastcgi_param' && count($args) === 3) {
                if (isset($params[$args[1]])) {
                    throw new RouteError('Duplicate FastCGI parameter');
                }
                $params[$args[1]] = $args[2];
            } elseif (count($args) === 4 && array_slice($args, 0, 2) === ['add_header', 'Allow'] && $args[3] === 'always' && $allow === null) {
                $allow = $args[2];
            } elseif (in_array($args[0], ['try_files', 'include', 'fastcgi_pass'], true) && !isset($directives[$args[0]])) {
                $directives[$args[0]] = $args;
            } else {
                throw new RouteError('Unsupported location directive: ' . $args[0]);
            }
        }
        $map = $result['maps'][$variable ?? ''] ?? null;
        if ($map === null || $map['source'] !== '$request_method' || !$map['entries']) {
            throw new RouteError('Missing literal method map for this location');
        }
        $script = $params['SCRIPT_NAME'] ?? '';
        if (!preg_match('~\A/' . preg_quote($variable, '~') . '(?:/[A-Za-z0-9_/-]+\.php)?\z~D', $script) || ($params['SCRIPT_FILENAME'] ?? '') !== '$document_root' . $script || ($directives['try_files'] ?? []) !== ['try_files', $script, '=404']) {
            throw new RouteError('Script parameters and file-existence check must select the same mapped file');
        }
        $include = $directives['include'][1] ?? '';
        if (count($directives['include'] ?? []) !== 2 || !in_array($include, ['fastcgi-params.conf', '../config/fastcgi-params.conf'], true)) {
            throw new RouteError('Use the project-owned config/fastcgi-params.conf include');
        }
        $pass = $directives['fastcgi_pass'][1] ?? '';
        self::fastcgiPass($pass);
        if (count($directives['fastcgi_pass'] ?? []) !== 2) {
            throw new RouteError('Invalid FastCGI destination');
        }
        unset($params['SCRIPT_NAME'], $params['SCRIPT_FILENAME']);
        foreach ($description['parameters'] as &$parameter) {
            $keys = array_keys($params, '$' . $parameter['capture'], true);
            if (count($keys) !== 1 || !preg_match('/\AROUTE_[A-Z][A-Z0-9_]*\z/D', $keys[0])) {
                throw new RouteError('Each capture needs one explicit ROUTE_* FastCGI parameter');
            }
            $parameter['serverKey'] = $keys[0];
            unset($params[$keys[0]]);
        }
        unset($parameter);
        if ($params) {
            throw new RouteError('Unrecognized additional FastCGI parameters');
        }
        $bindings = [];
        foreach ($map['entries'] as $method => $value) {
            $endpoint = 'web' . str_replace($variable, $value, $script);
            RoutePath::endpoint($endpoint);
            $bindings[$method] = $endpoint;
        }
        $allowValue = str_starts_with($allow ?? '', '$') ? ($result['maps'][$allow]['entries']['405'] ?? null) : $allow;
        $allowed = $allowValue === null ? [] : array_map('trim', explode(',', $allowValue));
        $methods = array_keys($bindings);
        sort($allowed); sort($methods);
        if ($allowed !== $methods) {
            $result['checks'][] = ['file' => $root . '/config/nginx.conf', 'line' => $node['line'], 'message' => 'Allow must list exactly the mapped methods: ' . implode(', ', array_keys($bindings))];
        }
        return $description + ['node' => $node, 'kind' => 'endpoint', 'bindings' => $bindings, 'variable' => $variable, 'include' => $include, 'pass' => $pass];
    }

    public static function fastcgiPass(string $pass): void
    {
        if (!preg_match('/\A[a-z][a-z0-9_]*\z/D', $pass)) {
            throw new RouteError('Use a named FastCGI upstream; define its address or socket in the environment');
        }
    }
}
