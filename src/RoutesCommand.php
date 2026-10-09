<?php

declare(strict_types=1);

namespace Unphramed\Tools;

use ErrorException;
use Throwable;

final class RoutesCommand
{
    /** @param list<string> $argv */
    public static function run(array $argv): int
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return self::execute($argv);
        } finally {
            restore_error_handler();
        }
    }

    /** Reject symlinks even if they currently resolve inside the project. */
    private static function projectPath(string $root, string $relative): string
    {
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RouteError('Invalid project-relative path');
            }
            $path .= '/' . $part;
            if (is_link($path)) {
                throw new RouteError('Symlinks are not supported for editable files or endpoint paths');
            }
        }
        return $path;
    }

    private static function validateTargets(string $root, array $routes): void
    {
        foreach ($routes as $route) {
            try {
                $file = self::projectPath($root, $route['endpoint']);
                if (!is_file($file)) {
                    throw new RouteError('Missing endpoint: ' . $route['endpoint']);
                }
                Process::run([PHP_BINARY, '-n', '-l', $file]);
            } catch (RouteError $error) {
                throw new RouteError($error->getMessage(), $route['line']);
            }
        }
    }

    private static function validateConfiguration(string $root, string $routeFile, ?string $httpFile = null): void
    {
        $params = self::projectPath($root, 'config/fastcgi-params.conf');
        if (!is_file($params)) {
            throw new RouteError('Missing config/fastcgi-params.conf');
        }
        try {
            $nodes = (new NginxSyntax(file_get_contents($params)))->parse();
        } catch (RouteError $error) {
            throw new RouteError($error->getMessage(), $error->sourceLine, $params);
        }
        $expected = [
            'QUERY_STRING' => '$query_string', 'REQUEST_METHOD' => '$request_method',
            'CONTENT_TYPE' => '$content_type', 'CONTENT_LENGTH' => '$content_length',
            'REQUEST_URI' => '$request_uri', 'DOCUMENT_URI' => '$document_uri',
            'DOCUMENT_ROOT' => '$document_root', 'SERVER_PROTOCOL' => '$server_protocol',
            'REQUEST_SCHEME' => '$scheme', 'HTTPS' => '$https',
            'GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_SOFTWARE' => 'nginx',
            'REMOTE_ADDR' => '$remote_addr', 'REMOTE_PORT' => '$remote_port',
            'SERVER_ADDR' => '$server_addr', 'SERVER_PORT' => '$server_port',
            'SERVER_NAME' => '$server_name', 'REDIRECT_STATUS' => '200', 'HTTP_PROXY' => '',
        ];
        foreach ($nodes as $node) {
            $key = $node['args'][1] ?? '';
            $args = ['fastcgi_param', $key, $expected[$key] ?? null];
            if ($key === 'HTTPS') {
                $args[] = 'if_not_empty';
            }
            if (!array_key_exists($key, $expected) || $node['children'] !== null || $node['args'] !== $args) {
                throw new RouteError('Shared FastCGI parameters differ from the supported profile', $node['line'], $params);
            }
            unset($expected[$key]);
        }
        if ($expected) {
            throw new RouteError('Missing shared FastCGI parameters: ' . implode(', ', array_keys($expected)), 1, $params);
        }
        ConfigurationCheck::check($root, $routeFile, $httpFile);
    }

    private static function outputResult(array $result, bool $json): void
    {
        if ($json) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
            return;
        }
        foreach ($result['diagnostics'] as $diagnostic) {
            echo $diagnostic['file'], ':', $diagnostic['line'], ': ', $diagnostic['message'], "\n";
        }
        foreach ($result['routes'] as $route) {
            echo ($route['methods'] ? implode(', ', $route['methods']) : '*'), ' ', $route['path'], ' -> ', ($route['endpoint'] ?? ($route['redirect']['status'] . ' ' . $route['redirect']['target'])),
                ' (', $route['file'], ':', $route['line'], ")\n";
        }
        foreach ($result['changes'] as $change) {
            echo "\n", $change['action'], ' ', $change['file'], "\n";
            foreach (explode("\n", rtrim($change['text'], "\n")) as $line) {
                echo '+', $line, "\n";
            }
        }
        if ($result['ok']) {
            echo $result['message'], "\n";
        }
    }

    private static function execute(array $argv): int
    {
        $json = in_array('--json', $argv, true);
        $sourceFile = 'config/routes.conf';
        $result = ['version' => 2, 'operation' => $argv[1] ?? '', 'ok' => false, 'routes' => [], 'diagnostics' => [], 'changes' => [], 'preconditions' => [], 'planSha256' => null, 'message' => ''];
        $temporary = [];
        try {
            if (!isset($argv[1]) || $argv[1] === '--help') {
                echo "Usage: vendor/bin/unphramed-routes list|check|add|add-read [--project DIR] [--json]\n";
                echo "add --path '/books/{id:int}/{slug:slug}' --method GET [--method HEAD] --endpoint web/GET/book.php [--create-endpoint] [--apply --expected-plan-sha256 HASH]\n";
                echo "add-read is the compatibility command: GET and HEAD, creating a new endpoint. No server reload.\n";
                echo "Optional --fastcgi-pass VALUE --params-include VALUE when no unambiguous existing connection exists.\n";
                return 0;
            }
            $command = $argv[1];
            if (!in_array($command, ['list', 'check', 'add', 'add-read'], true)) {
                throw new RouteError('Unknown operation');
            }
            $options = [];
            for ($i = 2; $i < count($argv); $i++) {
                $key = $argv[$i];
                if (isset($options[$key]) && $key !== '--method') {
                    throw new RouteError('Duplicate option: ' . $key);
                }
                if (in_array($key, ['--apply', '--json', '--create-endpoint'], true)) {
                    $options[$key] = true;
                } elseif (in_array($key, ['--project', '--path', '--endpoint', '--method', '--fastcgi-pass', '--params-include', '--expected-plan-sha256', '--expected-routes-sha256'], true) && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                    $value = $argv[++$i];
                    if ($key === '--method') {
                        $options[$key][] = $value;
                    } else {
                        $options[$key] = $value;
                    }
                } else {
                    throw new RouteError('Unknown option or missing value: ' . $key);
                }
            }
            $adding = in_array($command, ['add', 'add-read'], true);
            foreach (array_keys($options) as $key) {
                if (!$adding && !in_array($key, ['--project', '--json'], true)) {
                    throw new RouteError('Editing options belong to add or add-read');
                }
            }
            foreach (['--expected-plan-sha256', '--expected-routes-sha256'] as $guard) {
                if (isset($options[$guard]) && (!isset($options['--apply']) || preg_match('/\A[a-f0-9]{64}\z/D', $options[$guard]) !== 1)) {
                    throw new RouteError($guard . ' requires --apply and a lowercase SHA-256');
                }
            }
            if ($command === 'add' && isset($options['--expected-routes-sha256'])) {
                throw new RouteError('Use --expected-plan-sha256: additions can modify both HTTP maps and locations');
            }
            $root = realpath($options['--project'] ?? getcwd());
            if ($root === false || !is_dir($root)) {
                throw new RouteError('Project directory does not exist');
            }
            $sourceFile = self::projectPath($root, 'config/routes.conf');
            foreach (['config/routes.conf', 'config/http.conf'] as $relative) {
                $file = self::projectPath($root, $relative);
                if (!is_file($file) || !is_readable($file)) {
                    throw new RouteError('Missing readable ' . $relative, 1, $file);
                }
            }
            $inventory = NginxRoutes::read($root);
            $result['routes'] = $inventory['routes'];
            $result['diagnostics'] = $inventory['diagnostics'];
            if ($inventory['diagnostics']) {
                $result['message'] = 'Incomplete inventory: unsupported configuration. No edits are permitted.';
                self::outputResult($result, $json);
                return 1;
            }
            if ($command === 'list') {
                $result['message'] = 'Inventory read directly from Nginx. Run check to verify endpoints, Allow and isolated server syntax.';
            } else {
                if ($inventory['checks']) {
                    $result['diagnostics'] = $inventory['checks'];
                    $result['message'] = 'Method/Allow contract failed; no edits are permitted.';
                    self::outputResult($result, $json);
                    return 1;
                }
                self::validateTargets($root, array_filter($inventory['routes'], static fn($route) => $route['kind'] === 'endpoint'));
                if ($command === 'check') {
                    self::validateConfiguration($root, $sourceFile);
                    $result['message'] = 'Native dispatch, Allow, endpoints and isolated Nginx/FPM syntax passed. FastCGI connections and HTTP behavior were not verified.';
                } else {
                    if ($command === 'add-read') {
                        if (isset($options['--method'])) {
                            throw new RouteError('add-read already specifies GET and HEAD; use add for explicit methods');
                        }
                        $options['--method'] = ['GET', 'HEAD'];
                        $options['--create-endpoint'] = true;
                    }
                    if (isset($options['--expected-routes-sha256']) && !hash_equals($options['--expected-routes-sha256'], hash_file('sha256', $sourceFile))) {
                        throw new RouteError('Preview is stale: routes changed; preview again');
                    }
                    $plan = RouteEditor::preview($root, $options, $inventory);
                    if ($plan['inventory']['diagnostics'] || $plan['inventory']['checks']) {
                        throw new RouteError('Proposed configuration cannot be fully interpreted');
                    }
                    if (isset($options['--expected-plan-sha256']) && !hash_equals($options['--expected-plan-sha256'], $plan['planSha256'])) {
                        throw new RouteError('Preview is stale: configuration or endpoint changed; preview again');
                    }
                    $candidate = tempnam(dirname($sourceFile), '.routes-check-');
                    $temporary[] = $candidate;
                    file_put_contents($candidate, $plan['changes'][1]['text']);
                    $httpCandidate = tempnam(dirname($sourceFile), '.http-check-');
                    $temporary[] = $httpCandidate;
                    file_put_contents($httpCandidate, $plan['changes'][0]['text']);
                    foreach ($plan['changes'] as $change) {
                        if ($change['action'] === 'create') {
                            $code = tempnam(dirname($sourceFile), '.endpoint-check-');
                            $temporary[] = $code;
                            file_put_contents($code, $change['text']);
                            Process::run([PHP_BINARY, '-n', '-l', $code]);
                        }
                    }
                    self::validateConfiguration($root, $candidate, $httpCandidate);
                    $result['changes'] = $plan['changes'];
                    $result['preconditions'] = $plan['preconditions'];
                    $result['planSha256'] = $plan['planSha256'];
                    $result['message'] = 'Preview only. Use --apply to write these changes. Created endpoints return 501; existing PHP is preserved.';
                    if (isset($options['--apply'])) {
                        RouteEditor::apply($root, $plan);
                        $result['message'] = 'Associations applied. No service reloaded. Restart only your dedicated Nginx through your application workflow.';
                    }
                }
            }
            $result['ok'] = true;
        } catch (Throwable $error) {
            $result['changes'] = [];
            $result['preconditions'] = [];
            $result['planSha256'] = null;
            $result['diagnostics'][] = ['file' => $error instanceof RouteError ? ($error->sourceFile ?? $sourceFile) : $sourceFile, 'line' => $error instanceof RouteError ? $error->sourceLine : 1, 'message' => $error->getMessage()];
        } finally {
            foreach ($temporary as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        self::outputResult($result, $json);
        return $result['ok'] ? 0 : 1;
    }
}
