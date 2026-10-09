<?php

declare(strict_types=1);

namespace Unphramed\Tools;

final class NginxRoutes
{
    public static function parameter(string $path): ?string
    {
        if (!preg_match('~^/(?:[A-Za-z0-9_-]+|\{[a-z][a-z0-9_]*\})(?:/(?:[A-Za-z0-9_-]+|\{[a-z][a-z0-9_]*\}))*$~D', $path)) {
            throw new RouteError('Use slash-separated literal segments and at most one {lowercase_parameter}');
        }
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $path, $matches);
        if (count($matches[1]) > 1) {
            throw new RouteError('Only one positive integer parameter is supported');
        }
        $first = explode('/', trim($path, '/'))[0];
        if (in_array($first, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'assets'], true)) {
            throw new RouteError('This path belongs to a protected implementation or asset namespace');
        }
        return $matches[1][0] ?? null;
    }

    public static function endpoint(string $endpoint): void
    {
        if (!preg_match('~^web/GET/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php$~D', $endpoint)) {
            throw new RouteError('Endpoint must be a .php file below web/GET, without traversal');
        }
    }

    public static function render(string $path, string $endpoint, bool $websiteProfile = false): string
    {
        $parameter = self::parameter($path);
        self::endpoint($endpoint);
        $script = '$read_method/' . substr($endpoint, strlen('web/GET/'));
        $location = '= ' . $path;
        $routeInput = '';
        if ($parameter !== null) {
            $expression = str_replace('{' . $parameter . '}', '(?<route_' . $parameter . '>[1-9][0-9]{0,8})', $path);
            $location = '~ "^' . $expression . '$"';
            $routeInput = '    fastcgi_param ROUTE_' . strtoupper($parameter) . ' $route_' . $parameter . ";\n";
        }
        $allow = $websiteProfile ? '    add_header Allow $read_allow always;' . "\n" : '';
        $conditionalAllow = $websiteProfile ? '' : '        add_header Allow "GET, HEAD" always;' . "\n";
        $include = $websiteProfile ? '../config/fastcgi-params.conf' : 'fastcgi-params.conf';
        return <<<NGINX
location $location {
$allow    if (\$read_method = "") {
$conditionalAllow        return 405;
    }
    try_files /$script =404;
    include $include;
    fastcgi_param SCRIPT_FILENAME \$document_root/$script;
    fastcgi_param SCRIPT_NAME /$script;
$routeInput    fastcgi_pass example_php;
}

NGINX;
    }

    public static function inventory(string $source, bool $websiteProfile = false): array
    {
        $nodes = (new NginxSyntax($source))->parse();
        $routes = [];
        foreach ($nodes as $node) {
            try {
                if (count($node['args']) !== 3 || $node['args'][0] !== 'location' || $node['children'] === null) {
                    throw new RouteError('Only explicit read locations are supported in this route file');
                }
                [, $modifier, $pattern] = $node['args'];
                $path = $pattern;
                if ($modifier === '~') {
                    if (!str_starts_with($pattern, '^') || !str_ends_with($pattern, '$')) {
                        throw new RouteError('Parameter routes must be anchored');
                    }
                    $path = preg_replace('~\(\?<route_([a-z][a-z0-9_]*)>\[1-9\]\[0-9\]\{0,8\}\)~', '{$1}', substr($pattern, 1, -1), -1, $count);
                    if ($count !== 1) {
                        throw new RouteError('Expected exactly one bounded positive integer capture');
                    }
                } elseif ($modifier !== '=') {
                    throw new RouteError('Unsupported location modifier');
                }
                $parameter = self::parameter($path);
                if (($modifier === '~') !== ($parameter !== null)) {
                    throw new RouteError('Location modifier does not match its parameter');
                }
                $script = null;
                foreach ($node['children'] as $child) {
                    if (array_slice($child['args'], 0, 2) === ['fastcgi_param', 'SCRIPT_NAME']) {
                        $script = $child['args'][2] ?? null;
                    }
                }
                if ($script === null) {
                    throw new RouteError('Missing SCRIPT_NAME template');
                }
                if (!str_starts_with($script, '/$read_method/')) {
                    throw new RouteError('SCRIPT_NAME must select the directory through $read_method');
                }
                $endpoint = 'web/GET/' . substr($script, strlen('/$read_method/'));
                $expected = (new NginxSyntax(self::render($path, $endpoint, $websiteProfile)))->parse();
                if (NginxSyntax::shape([$node]) !== NginxSyntax::shape($expected)) {
                    throw new RouteError('Location differs from the supported GET/HEAD profile (directives, order or FastCGI contract)');
                }
                $routes[] = ['path' => $path, 'methods' => ['GET', 'HEAD'], 'endpoint' => $endpoint, 'parameter' => $parameter, 'line' => $node['line'], 'offset' => $node['start']];
            } catch (RouteError $error) {
                throw new RouteError($error->getMessage(), $node['line']);
            }
        }
        foreach ($routes as $index => $route) {
            foreach (array_slice($routes, 0, $index) as $previous) {
                if (self::overlap($route['path'], $previous['path'])) {
                    throw new RouteError('Overlapping read routes: ' . $previous['path'] . ' and ' . $route['path'], $route['line']);
                }
            }
        }
        return $routes;
    }

    /** Conservative overlap check for the narrow segment grammar, not arbitrary regex. */
    private static function overlap(string $a, string $b): bool
    {
        $left = explode('/', $a);
        $right = explode('/', $b);
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $i => $segment) {
            $other = $right[$i];
            $aParameter = str_starts_with($segment, '{');
            $bParameter = str_starts_with($other, '{');
            if ($aParameter && $bParameter) {
                continue;
            }
            if ($aParameter || $bParameter) {
                if (!preg_match('/^[1-9][0-9]{0,8}$/D', $aParameter ? $other : $segment)) {
                    return false;
                }
            } elseif ($segment !== $other) {
                return false;
            }
        }
        return true;
    }
}
