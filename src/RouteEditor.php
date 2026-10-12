<?php

declare(strict_types=1);

namespace Unphramed\Tools;

use Throwable;

final class RouteEditor
{
    public static function path(string $root, string $relative): string
    {
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RouteError('Invalid project-relative path');
            }
            $path .= '/' . $part;
            if (is_link($path)) {
                throw new RouteError('Symlinks are not supported');
            }
        }
        return $path;
    }

    public static function preview(string $root, array $options, array $inventory): array
    {
        $description = RoutePath::describe($options['--path'] ?? '');
        $methods = $options['--method'] ?? ['GET'];
        if (!$methods || count(array_unique($methods)) !== count($methods)) {
            throw new RouteError('Supply unique --method values');
        }
        foreach ($methods as $method) RoutePath::method($method);
        if (!isset($options['--endpoint']) && count($methods) !== 1) {
            throw new RouteError('Choose one method for endpoint inference, or specify --endpoint to share a file explicitly.');
        }
        $endpoint = $options['--endpoint'] ?? RoutePath::inferEndpoint($options['--path'], $methods[0]);
        RoutePath::endpoint($endpoint);
        $destination = self::path($root, $endpoint);
        if (isset($options['--create-endpoint']) && file_exists($destination)) {
            throw new RouteError('Endpoint already exists; creation never overwrites a file');
        }
        if (!is_file($destination)) $options['--create-endpoint'] = true;
        $selected = null;
        foreach ($inventory['locations'] as $location) {
            if ($location['path'] === $description['path']) {
                if ($location['kind'] !== 'endpoint' || $selected !== null) {
                    throw new RouteError('Path already belongs to a redirect or competing locations');
                }
                $selected = $location;
            } elseif (self::overlap($location['path'], $description['path'])) {
                throw new RouteError('Potential route collision with ' . $location['path']);
            }
        }
        $bindings = $selected['bindings'] ?? [];
        foreach ($methods as $method) {
            if (isset($bindings[$method])) {
                throw new RouteError('Method already mapped for this path: ' . $method);
            }
            $bindings[$method] = $endpoint;
        }
        $passes = array_unique(array_column(array_filter($inventory['locations'], static fn($location) => $location['kind'] === 'endpoint'), 'pass'));
        $includes = array_unique(array_column(array_filter($inventory['locations'], static fn($location) => $location['kind'] === 'endpoint'), 'include'));
        $pass = $options['--fastcgi-pass'] ?? $selected['pass'] ?? (count($passes) === 1 ? reset($passes) : null);
        $include = $options['--params-include'] ?? $selected['include'] ?? (count($includes) === 1 ? reset($includes) : null);
        if ($pass === null || $include === null) {
            throw new RouteError('FastCGI settings are ambiguous: supply --fastcgi-pass and --params-include');
        }
        NginxRoutes::fastcgiPass($pass);
        if (!in_array($include, ['fastcgi-params.conf', '../config/fastcgi-params.conf'], true)) {
            throw new RouteError('Select the project-owned FastCGI parameter include');
        }
        $base = '$unphramed_' . substr(hash('sha256', $description['path']), 0, 12);
        $suffix = 0;
        while (isset($inventory['maps'][$base . '_handler'])) {
            $base = '$unphramed_' . substr(hash('sha256', $description['path']), 0, 12) . '_' . ++$suffix;
        }
        $handler = $base . '_handler';
        $mapText = "\nmap \$request_method $handler {\n    default \"\";\n";
        foreach ($bindings as $method => $target) {
            $mapText .= "    $method " . substr($target, 4) . ";\n";
        }
        $allowText = implode(', ', array_keys($bindings));
        $mapText .= "}\n";
        if ($selected !== null) {
            // Reuse the original capture/environment contract when extending a route.
            $description = $selected;
        }
        $pattern = $description['modifier'] === '~' ? '"' . $description['pattern'] . '"' : $description['pattern'];
        $locationText = "location {$description['modifier']} $pattern {\n    add_header Allow \"$allowText\" always;\n    if ($handler = \"\") { return 405; }\n    try_files /$handler =404;\n    include \"$include\";\n    fastcgi_param SCRIPT_FILENAME \$document_root/$handler;\n    fastcgi_param SCRIPT_NAME /$handler;\n";
        foreach ($description['parameters'] as $parameter) {
            $locationText .= "    fastcgi_param {$parameter['serverKey']} \${$parameter['capture']};\n";
        }
        $locationText .= "    fastcgi_pass $pass;\n}";
        $routeFile = self::path($root, 'config/nginx.conf');
        $source = file_get_contents($routeFile);
        $server = $inventory['server'];
        $indent = '    ';
        $locationText = $indent . str_replace("\n", "\n" . $indent, $locationText);
        if ($selected === null) {
            // Insert before the server's closing brace, never outside its context.
            $offset = $server['end'] - 1;
            $lineStart = strrpos(substr($source, 0, $offset), "\n");
            if ($lineStart !== false && trim(substr($source, $lineStart + 1, $offset - $lineStart - 1)) === '') $offset = $lineStart + 1;
            $candidate = substr($source, 0, $offset) . "\n$locationText\n" . substr($source, $offset);
        } else {
            $node = $selected['node'];
            $old = substr($source, $node['start'], $node['end'] - $node['start']);
            $comments = (new NginxSyntax($old))->comments();
            $locationText = implode("\n", $comments) . ($comments ? "\n" : '') . ltrim($locationText);
            $candidate = substr($source, 0, $node['start']) . $locationText . substr($source, $node['end']);
        }
        $offset = $server['start'];
        $candidate = substr($candidate, 0, $offset) . ltrim($mapText, "\n") . "\n" . substr($candidate, $offset);
        $changes = [
            ['file' => $routeFile, 'action' => 'update', 'beforeSha256' => hash('sha256', $source), 'text' => $candidate],
        ];
        if (isset($options['--create-endpoint'])) {
            $changes[] = ['file' => $destination, 'action' => 'create', 'beforeSha256' => null, 'text' => "<?php\n\ndeclare(strict_types=1);\n\n// TODO: validate HTTP input, authorize the action and invoke your application.\n// Route captures are supplied in ROUTE_* FastCGI parameters; validate them here.\nheader('Content-Type: text/plain; charset=UTF-8');\nheader('Cache-Control: no-store');\nhttp_response_code(501);\necho 'Not implemented';\n"];
        }
        $preconditions = [];
        foreach (array_unique(array_merge(['config/nginx.conf', 'config/fastcgi-params.conf', 'bootstrap.php', $endpoint], array_column(array_filter($inventory['routes'], static fn($route) => $route['kind'] === 'endpoint'), 'endpoint'))) as $relative) {
            $file = self::path($root, $relative);
            $preconditions[] = ['file' => $file, 'sha256' => is_file($file) ? hash_file('sha256', $file) : null];
        }
        $plan = ['changes' => $changes, 'preconditions' => $preconditions];
        $plan['planSha256'] = hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $plan['inventory'] = NginxRoutes::read($root, $candidate);
        return $plan;
    }

    private static function overlap(string $left, string $right): bool
    {
        $left = explode('/', $left);
        $right = explode('/', $right);
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $index => $part) {
            $other = $right[$index];
            $a = preg_match('/\A\{[^:}]+:(int|slug|string)\}\z/D', $part, $matchA);
            $b = preg_match('/\A\{[^:}]+:(int|slug|string)\}\z/D', $other, $matchB);
            if ($a && $b) {
                continue;
            }
            if ($a || $b) {
                if (!preg_match('~\A' . RoutePath::TYPES[$a ? $matchA[1] : $matchB[1]] . '\z~D', $a ? $other : $part)) {
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
            $preconditions = $plan['preconditions'];
            usort($preconditions, static fn($a, $b) => strcmp($a['file'], $b['file']));
            foreach ($preconditions as $condition) {
                self::path($root, substr($condition['file'], strlen($root) + 1));
                if ($condition['sha256'] !== null) {
                    $updating = in_array($condition['file'], array_column($plan['changes'], 'file'), true);
                    $handle = fopen($condition['file'], $updating ? 'r+' : 'r');
                    if ($handle === false || !flock($handle, LOCK_EX)) {
                        throw new RouteError('Cannot lock preview input');
                    }
                    $locks[] = $handle;
                    if ($updating) {
                        $updateHandles[$condition['file']] = $handle;
                    }
                }
            }
            foreach ($preconditions as $condition) {
                $actual = is_file($condition['file']) ? hash_file('sha256', $condition['file']) : null;
                if ($actual !== $condition['sha256'] || ($actual === null && file_exists($condition['file']))) {
                    throw new RouteError('Preview is stale; preview again');
                }
            }
            foreach ($plan['changes'] as $change) {
                $file = $change['file'];
                if ($change['action'] === 'create') {
                    $missing = [];
                    for ($parent = dirname($file); !is_dir($parent); $parent = dirname($parent)) {
                        $missing[] = $parent;
                    }
                    foreach (array_reverse($missing) as $directory) {
                        if (!mkdir($directory)) {
                            throw new RouteError('Cannot create endpoint directory');
                        }
                        $directories[] = $directory;
                    }
                    $handle = @fopen($file, 'x');
                    if ($handle === false) {
                        throw new RouteError('Cannot exclusively create endpoint');
                    }
                    $created[] = $file;
                } else {
                    $written[$file] = file_get_contents($file);
                    $handle = $updateHandles[$file];
                    if (!rewind($handle) || !ftruncate($handle, 0)) {
                        throw new RouteError('Cannot update configuration file');
                    }
                }
                try {
                    if (fwrite($handle, $change['text']) !== strlen($change['text']) || !fflush($handle)) {
                        throw new RouteError('Incomplete file write');
                    }
                } finally {
                    if ($change['action'] === 'create') {
                        fclose($handle);
                    }
                }
            }
        } catch (Throwable $error) {
            foreach ($written as $file => $before) {
                $handle = $updateHandles[$file];
                rewind($handle);
                ftruncate($handle, 0);
                if (fwrite($handle, $before) !== strlen($before) || !fflush($handle)) {
                    throw new RouteError('Rollback failed; recover configuration through application version control');
                }
            }
            foreach ($created as $file) {
                unlink($file);
            }
            foreach (array_reverse($directories) as $directory) {
                rmdir($directory);
            }
            throw $error;
        } finally {
            foreach ($locks as $handle) {
                flock($handle, LOCK_UN); fclose($handle);
            }
        }
    }
}
