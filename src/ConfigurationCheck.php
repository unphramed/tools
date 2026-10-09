<?php

declare(strict_types=1);

namespace Unphramed\Tools;

/** Syntax-check a disposable envelope for the supported profile; never start services. */
final class ConfigurationCheck
{
    public static function check(string $root, string $routes, ?string $httpFile = null): void
    {
        foreach (['vendor/autoload.php', 'bootstrap.php'] as $file) {
            if (!is_file($root . '/' . $file)) {
                throw new RouteError('Missing project file: ' . $file, 1, $root . '/' . $file);
            }
        }
        $user = posix_getpwuid(posix_geteuid());
        $group = posix_getgrgid(posix_getegid());
        if ($user === false || $group === false) {
            throw new RouteError('Cannot resolve the current Unix user and group');
        }
        $work = sys_get_temp_dir() . '/unphramed-config-' . bin2hex(random_bytes(12));
        if (!mkdir($work, 0700)) {
            throw new RouteError('Cannot create private configuration-check directory');
        }
        try {
            $nginxPid = self::nginxQuote($work . '/nginx.pid');
            $nginxLog = self::nginxQuote($work . '/nginx-error.log');
            $accessLog = self::nginxQuote($work . '/access.log');
            $bodyPath = self::nginxQuote($work . '/body');
            $fastcgiPath = self::nginxQuote($work . '/fastcgi');
            $web = self::nginxQuote($root . '/web');
            $http = self::nginxQuote($httpFile ?? $root . '/config/http.conf');
            $upstreams = '';
            $inventory = NginxRoutes::read($root, file_get_contents($httpFile ?? $root . '/config/http.conf'), file_get_contents($routes));
            foreach (array_unique(array_column(array_filter($inventory['locations'], static fn($location) => $location['kind'] === 'endpoint'), 'pass')) as $pass) {
                if (preg_match('/\A[a-z][a-z0-9_]*\z/D', $pass)) {
                    $upstreams .= 'upstream ' . $pass . ' { server 127.0.0.1:9001; }' . "\n";
                }
            }
            $hasRoot = in_array('/', array_column($inventory['locations'], 'path'), true);
            $fallback = $hasRoot ? '' : 'location / { return 404; }';
            $routeFile = self::nginxQuote($routes);
            $params = self::nginxQuote($root . '/config/fastcgi-params.conf');
            mkdir($work . '/.runtime', 0700);
            mkdir($work . '/config', 0700);
            file_put_contents($work . '/config/fastcgi-params.conf', "include $params;\n");
            file_put_contents($work . '/.runtime/fastcgi-params.conf', "include $params;\n");
            // Fixed loopback ports are syntax-test values only: no socket is opened.
            file_put_contents($work . '/.runtime/nginx.conf', <<<NGINX
pid $nginxPid;
error_log $nginxLog;
events {}
http {
    access_log $accessLog;
    client_body_temp_path $bodyPath;
    fastcgi_temp_path $fastcgiPath;
    types { text/css css; text/html html; }
    default_type application/octet-stream;
    include $http;
    $upstreams
    server {
        listen 127.0.0.1:8081;
        server_name localhost;
        root $web;
        location ~ ^/(?:GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS)(?:/|$) { return 404; }
        location ~ \.php(?:/|$) { return 404; }
        include $routeFile;
        location /assets/ { try_files \$uri =404; }
        $fallback
    }
}
NGINX);
            $pid = self::iniQuote($work . '/fpm.pid');
            $fpmLog = self::iniQuote($work . '/fpm-error.log');
            $phpLog = self::iniQuote($work . '/php-error.log');
            $bootstrap = self::iniQuote($root . '/bootstrap.php');
            file_put_contents($work . '/fpm.conf', <<<INI
[global]
pid = $pid
error_log = $fpmLog
[unphramed_check]
user = {$user['name']}
group = {$group['name']}
listen = 127.0.0.1:9001
listen.allowed_clients = 127.0.0.1
pm = ondemand
pm.max_children = 2
pm.process_idle_timeout = 10s
security.limit_extensions = .php
clear_env = yes
catch_workers_output = yes
php_admin_value[auto_prepend_file] = $bootstrap
php_admin_value[error_log] = $phpLog
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_flag[zend.exception_ignore_args] = on
INI);
            Process::run(['nginx', '-p', $work . '/', '-c', $work . '/.runtime/nginx.conf', '-e', $work . '/nginx-error.log', '-t']);
            Process::run(['php-fpm', '-n', '-t', '-y', $work . '/fpm.conf']);
        } finally {
            self::removeDirectory($work);
        }
    }

    private static function nginxQuote(string $value): string
    {
        if (strpbrk($value, "\n\r\0$") !== false) {
            throw new RouteError('Unsupported character in Nginx path');
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private static function iniQuote(string $value): string
    {
        if (strpbrk($value, "\n\r\0\"") !== false) {
            throw new RouteError('Unsupported character in FPM path');
        }
        return '"' . $value . '"';
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
