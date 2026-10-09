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

    private static function stub(string $path): string
    {
        $parameter = NginxRoutes::parameter($path);
        $validation = '';
        if ($parameter !== null) {
            $serverKey = 'ROUTE_' . strtoupper($parameter);
            $validation = <<<PHP
    \$id = filter_var(
        \$_SERVER['$serverKey'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 999999999]],
    );
    if (!is_int(\$id)) {
        http_response_code(400);
        echo 'Invalid route identifier';
        exit;
    }

    PHP;
        }
        return <<<PHP
    <?php

    declare(strict_types=1);

    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    $validation
    // TODO: invoke your application and render its result. Do not mutate on GET/HEAD.
    http_response_code(501);
    echo 'Not implemented';

    PHP;
    }

    /** Validate the Nginx method map before interpreting its GET endpoint templates. */
    private static function validateReadMethods(string $root): bool
    {
        $file = self::projectPath($root, 'config/http.conf');
        if (!is_file($file)) {
            throw new RouteError('Missing config/http.conf', 1, $file);
        }
        try {
            $nodes = (new NginxSyntax(file_get_contents($file)))->parse();
        } catch (RouteError $error) {
            throw new RouteError($error->getMessage(), $error->sourceLine, $file);
        }
        if (!in_array(count($nodes), [1, 2], true) || $nodes[0]['args'] !== ['map', '$request_method', '$read_method'] || $nodes[0]['children'] === null) {
            throw new RouteError('Expected one map from $request_method to $read_method', $nodes[0]['line'] ?? 1, $file);
        }
        $expected = ['default' => '', 'GET' => 'GET', 'HEAD' => 'GET'];
        foreach ($nodes[0]['children'] as $node) {
            $method = $node['args'][0];
            if (!array_key_exists($method, $expected) || $node['children'] !== null || $node['args'] !== [$method, $expected[$method]]) {
                throw new RouteError('Method map differs from the supported GET/HEAD policy', $node['line'], $file);
            }
            unset($expected[$method]);
        }
        if ($expected) {
            throw new RouteError('Missing method map entries: ' . implode(', ', array_keys($expected)), $nodes[0]['line'], $file);
        }
        if (count($nodes) === 2) {
            $allow = (new NginxSyntax('map $status $read_allow { default ""; 405 "GET, HEAD"; }'))->parse();
            if (NginxSyntax::shape([$nodes[1]]) !== NginxSyntax::shape($allow)) {
                throw new RouteError('Unsupported Allow map', $nodes[1]['line'], $file);
            }
            return true;
        }
        return false;
    }

    private static function validateConfiguration(string $root, string $routeFile): void
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
        ConfigurationCheck::check($root, $routeFile);
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
            echo implode(', ', $route['methods']), ' ', $route['path'], ' -> ', $route['endpoint'],
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
        $result = ['version' => 1, 'operation' => $argv[1] ?? '', 'ok' => false, 'routes' => [], 'diagnostics' => [], 'changes' => [], 'message' => ''];
        $temporary = [];
        $lock = null;
        $createdDirectories = [];
        $createdEndpoint = null;
        try {
            if (!isset($argv[1]) || $argv[1] === '--help') {
                echo "Usage: vendor/bin/unphramed-routes list|check|add-read [--project DIR] [--json]\n";
                echo "add-read --path '/notes/{id}' --endpoint web/GET/note.php [--apply [--expected-routes-sha256 HASH]]\n";
                echo "Edits only the demonstrated native Nginx GET/HEAD profile. No server reload.\n";
                return 0;
            }
            $command = $argv[1];
            if (!in_array($command, ['list', 'check', 'add-read'], true)) {
                throw new RouteError('Unknown operation');
            }
            $options = [];
            for ($i = 2; $i < count($argv); $i++) {
                $key = $argv[$i];
                if (isset($options[$key])) {
                    throw new RouteError('Duplicate option: ' . $key);
                }
                if (in_array($key, ['--apply', '--json'], true)) {
                    $options[$key] = true;
                } elseif (in_array($key, ['--project', '--path', '--endpoint', '--expected-routes-sha256'], true) && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                    $options[$key] = $argv[++$i];
                } else {
                    throw new RouteError('Unknown option or missing value: ' . $key);
                }
            }
            if ($command !== 'add-read' && (isset($options['--apply']) || isset($options['--path']) || isset($options['--endpoint']))) {
                throw new RouteError('--apply, --path and --endpoint belong to add-read');
            }
            if (isset($options['--expected-routes-sha256']) && (
                $command !== 'add-read' || !isset($options['--apply']) ||
                preg_match('/\A[a-f0-9]{64}\z/D', $options['--expected-routes-sha256']) !== 1
            )) {
                throw new RouteError('--expected-routes-sha256 requires add-read --apply and a lowercase SHA-256');
            }
            $root = realpath($options['--project'] ?? getcwd());
            if ($root === false || !is_dir($root)) {
                throw new RouteError('Project directory does not exist');
            }
            $sourceFile = self::projectPath($root, 'config/routes.conf');
            if (!is_file($sourceFile) || !is_readable($sourceFile)) {
                throw new RouteError('Missing readable config/routes.conf');
            }
            $source = file_get_contents($sourceFile);
            if (isset($options['--expected-routes-sha256']) && !hash_equals($options['--expected-routes-sha256'], hash('sha256', $source))) {
                throw new RouteError('Preview is stale: routes changed; preview again');
            }
            $websiteProfile = self::validateReadMethods($root);
            $routes = NginxRoutes::inventory($source, $websiteProfile);
            $result['routes'] = array_map(static fn(array $route): array => ['file' => $sourceFile] + $route, $routes);
            if ($command === 'check') {
                self::validateTargets($root, $routes);
                self::validateConfiguration($root, $sourceFile);
                $result['message'] = 'Route profile, endpoints, Nginx and FPM configuration checks passed. No HTTP behavior or live service was changed.';
            } elseif ($command === 'list') {
                $result['message'] = 'Inventory read directly from Nginx. Endpoint availability and server syntax are checked by check.';
            } else {
                if (!isset($options['--path'], $options['--endpoint'])) {
                    throw new RouteError('add-read needs --path and --endpoint');
                }
                $path = $options['--path'];
                $endpoint = $options['--endpoint'];
                $addition = "\n" . NginxRoutes::render($path, $endpoint, $websiteProfile);
                $candidate = $source . (str_ends_with($source, "\n") ? '' : "\n") . $addition;
                NginxRoutes::inventory($candidate, $websiteProfile);
                self::validateTargets($root, $routes);
                $destination = self::projectPath($root, $endpoint);
                if (file_exists($destination)) {
                    throw new RouteError('Endpoint already exists; no file will be overwritten');
                }
                $code = self::stub($path);
                $candidateFile = tempnam(dirname($sourceFile), '.routes-check-');
                $temporary[] = $candidateFile;
                file_put_contents($candidateFile, $candidate);
                $codeFile = tempnam(dirname($sourceFile), '.endpoint-check-');
                $temporary[] = $codeFile;
                file_put_contents($codeFile, $code);
                Process::run([PHP_BINARY, '-n', '-l', $codeFile]);
                self::validateConfiguration($root, $candidateFile);
                $result['changes'] = [
                    ['file' => $sourceFile, 'action' => 'append', 'offset' => strlen($source), 'text' => substr($candidate, strlen($source)), 'beforeSha256' => hash('sha256', $source)],
                    ['file' => $destination, 'action' => 'create', 'text' => $code],
                ];
                $result['message'] = 'Preview only. Use --apply to write these changes; the endpoint returns 501 until implemented.';
                if (isset($options['--apply'])) {
                    $lock = fopen($sourceFile, 'r');
                    if ($lock === false || !flock($lock, LOCK_EX)) {
                        throw new RouteError('Could not lock routes for editing');
                    }
                    if (file_get_contents($sourceFile) !== $source || self::projectPath($root, $endpoint) !== $destination) {
                        throw new RouteError('Files changed during validation; run the command again');
                    }
                    $parent = dirname($destination);
                    $missing = [];
                    while (!is_dir($parent)) {
                        $missing[] = $parent;
                        $parent = dirname($parent);
                    }
                    foreach (array_reverse($missing) as $directory) {
                        if (!mkdir($directory)) {
                            throw new RouteError('Cannot create endpoint directory');
                        }
                        $createdDirectories[] = $directory;
                    }
                    $handle = @fopen($destination, 'x');
                    if ($handle === false) {
                        throw new RouteError('Cannot exclusively create endpoint; existing files are never overwritten');
                    }
                    $createdEndpoint = $destination;
                    $written = fwrite($handle, $code);
                    fclose($handle);
                    if ($written !== strlen($code)) {
                        throw new RouteError('Could not write complete endpoint');
                    }
                    if (file_get_contents($sourceFile) !== $source) {
                        throw new RouteError('Routes changed before application; retry');
                    }
                    chmod($candidateFile, fileperms($sourceFile) & 0777);
                    if (!rename($candidateFile, $sourceFile)) {
                        throw new RouteError('Could not replace route file');
                    }
                    // The two-file operation is complete; rollback below no longer owns these files.
                    $createdEndpoint = null;
                    $createdDirectories = [];
                    $result['message'] = 'Route and endpoint created. No service reloaded. Reload the route through your project deployment workflow.';
                }
            }
            $result['ok'] = true;
        } catch (Throwable $error) {
            $result['changes'] = [];
            $result['diagnostics'][] = ['file' => $error instanceof RouteError ? ($error->sourceFile ?? $sourceFile) : $sourceFile, 'line' => $error instanceof RouteError ? $error->sourceLine : 1, 'message' => $error->getMessage()];
        } finally {
            if ($createdEndpoint !== null) {
                unlink($createdEndpoint);
            }
            foreach (array_reverse($createdDirectories) as $directory) {
                rmdir($directory);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
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
