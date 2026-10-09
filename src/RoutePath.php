<?php

declare(strict_types=1);

namespace Unphramed\Tools;

final class RoutePath
{
    public const TYPES = ['int' => '[1-9][0-9]{0,8}', 'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'string' => '[^/]{1,255}'];

    public static function describe(string $path): array
    {
        if ($path === '/') {
            return ['path' => '/', 'pattern' => '/', 'parameters' => [], 'modifier' => '='];
        }
        if (!str_starts_with($path, '/') || str_ends_with($path, '/')) {
            throw new RouteError('Use an absolute path without a trailing slash');
        }
        $parts = explode('/', substr($path, 1));
        $parameters = [];
        $patterns = [];
        foreach ($parts as $part) {
            if (preg_match('/\A\{([a-z][a-z0-9_]*)(?::(int|slug|string))?\}\z/D', $part, $match)) {
                $name = $match[1];
                if (isset($parameters[$name])) {
                    throw new RouteError('Duplicate route parameter: ' . $name);
                }
                $type = $match[2] ?? 'int';
                $parameters[$name] = ['name' => $name, 'type' => $type, 'capture' => 'route_' . $name, 'serverKey' => 'ROUTE_' . strtoupper($name)];
                $patterns[] = '(?<route_' . $name . '>' . self::TYPES[$type] . ')';
            } elseif (preg_match('/\A[A-Za-z0-9_-]+\z/D', $part)) {
                $patterns[] = $part;
            } else {
                throw new RouteError('Use literal segments or {name:int}, {name:slug}, {name:string}');
            }
        }
        if (in_array($parts[0], ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'assets'], true)) {
            throw new RouteError('This path is reserved for implementation files or assets');
        }
        return ['path' => preg_replace('/\{([a-z][a-z0-9_]*)\}/', '{$1:int}', $path), 'pattern' => $parameters ? '^/' . implode('/', $patterns) . '$' : $path, 'parameters' => array_values($parameters), 'modifier' => $parameters ? '~' : '='];
    }

    public static function fromLocation(array $args): array
    {
        if (count($args) !== 3 || $args[0] !== 'location' || !in_array($args[1], ['=', '~'], true)) {
            throw new RouteError('Unsupported location: use an exact path or an anchored typed route');
        }
        if ($args[1] === '=') {
            $description = self::describe($args[2]);
            if ($description['parameters']) {
                throw new RouteError('Exact Nginx locations cannot contain typed captures');
            }
            return $description;
        }
        $pattern = $args[2];
        $path = $pattern;
        $captures = [];
        foreach (self::TYPES as $type => $expression) {
            $path = preg_replace_callback('~\(\?<([a-z][a-z0-9_]*)>' . preg_quote($expression, '~') . '\)~', static function ($match) use ($type, &$captures) {
                $name = preg_replace('/^route_/', '', $match[1]);
                $captures[$name] = $match[1];
                return '{' . $name . ':' . $type . '}';
            }, $path);
        }
        if (!str_starts_with($path, '^') || !str_ends_with($path, '$')) {
            throw new RouteError('Typed routes must be anchored');
        }
        $description = self::describe(substr($path, 1, -1));
        if (!$description['parameters']) {
            throw new RouteError('Unrecognized route expression');
        }
        foreach ($description['parameters'] as &$parameter) {
            $parameter['capture'] = $captures[$parameter['name']];
        }
        unset($parameter);
        $description['pattern'] = $pattern;
        return $description;
    }

    public static function endpoint(string $endpoint): void
    {
        if (!preg_match('~\Aweb/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php\z~D', $endpoint)) {
            throw new RouteError('Select a .php file under web/, without traversal');
        }
    }

    public static function method(string $method): void
    {
        if (!preg_match('/\A[A-Z_-]+\z/D', $method) || in_array($method, ['TRACE', 'CONNECT'], true)) {
            throw new RouteError('This Nginx FastCGI profile accepts uppercase method names with hyphens/underscores, including custom methods; TRACE and CONNECT require a different server profile');
        }
    }
}
