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
        \set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((\error_reporting() & $severity) === 0) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return self::execute($argv);
        } finally {
            \restore_error_handler();
        }
    }

    /** Reject symlinks even if they currently resolve inside the project. */
    private static function projectPath(string $root, string $relative): string
    {
        $path = $root;
        foreach (\explode("/", $relative) as $part) {
            if ($part === "" || $part === "." || $part === "..") {
                throw new RouteError("Invalid project-relative path");
            }
            $path .= "/" . $part;
            if (\is_link($path)) {
                throw new RouteError("Symlinks are not supported for editable files or endpoint paths");
            }
        }
        return $path;
    }

    private static function validateTargets(string $root, array $routes): void
    {
        foreach ($routes as $route) {
            try {
                $file = self::projectPath($root, $route["endpoint"]);
                if (!\is_file($file)) {
                    throw new RouteError("Missing endpoint: " . $route["endpoint"]);
                }
                Process::run([PHP_BINARY, "-n", "-d", "short_open_tag=0", "-l", $file]);
            } catch (RouteError $error) {
                throw new RouteError($error->getMessage(), $route["line"]);
            }
        }
    }

    private static function outputResult(array $result, bool $json, bool $previewShown = false, ?string $root = null): void
    {
        // Keep redirects internally for collision checks, but expose only PHP dispatch.
        $result["routes"] = \array_values(\array_filter($result["routes"], static fn($route) => $route["kind"] === "endpoint"));
        if ($json) {
            echo \json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
            return;
        }
        ConsoleOutput::diagnostics($result["diagnostics"]);
        if ($previewShown) {
            if ($result["ok"]) echo $result["message"], "\n";
            return;
        }
        if ($result["routes"]) {
            $rows = [];
            foreach ($result["routes"] as $route) {
                $source = $route["file"];
                $prefix = $root === null ? null : \rtrim($root, "/") . "/";
                if ($prefix !== null && \str_starts_with($source, $prefix)) $source = \substr($source, \strlen($prefix));
                $rows[] = [
                    $route["methods"] ? \implode(", ", $route["methods"]) : "*",
                    $route["path"],
                    $route["endpoint"],
                    $source . ":" . $route["line"],
                ];
            }
            ConsoleOutput::table(["METHOD", "PATH", "TARGET", "SOURCE"], $rows);
        }
        ConsoleOutput::changes($result["changes"]);
        if ($result["ok"]) {
            echo $result["message"], "\n";
        }
    }

    private static function execute(array $argv): int
    {
        $json = \in_array("--json", $argv, true);
        $sourceFile = "config/nginx.conf";
        $result = ["version" => 2, "operation" => $argv[1] ?? "", "ok" => false, "routes" => [], "routingSources" => [], "diagnostics" => [], "changes" => [], "preconditions" => [], "planSha256" => null, "endpoint" => null, "message" => ""];
        $temporary = [];
        $previewShown = false;
        try {
            if (!isset($argv[1]) || $argv[1] === "--help") {
                ConsoleOutput::help($json);
                return 0;
            }
            $command = $argv[1];
            if (!\in_array($command, ["list", "check", "add"], true)) {
                throw new RouteError("Unknown operation");
            }
            $options = [];
            for ($i = 2; $i < \count($argv); $i++) {
                $key = $argv[$i];
                if (isset($options[$key]) && !\in_array($key, ["--method", "--constraint", "--choices", "--unused"], true)) {
                    throw new RouteError("Duplicate option: " . $key);
                }
                if (\in_array($key, ["--dry-run", "--json", "--create-endpoint"], true)) {
                    $options[$key] = true;
                } elseif (\in_array($key, ["--project", "--path", "--endpoint", "--method", "--constraint", "--choices", "--unused", "--expected-plan-sha256", "--expected-routes-sha256"], true) && isset($argv[$i + 1]) && !\str_starts_with($argv[$i + 1], "--")) {
                    $value = $argv[++$i];
                    if (\in_array($key, ["--method", "--constraint", "--choices", "--unused"], true)) {
                        $options[$key][] = $value;
                    } else {
                        $options[$key] = $value;
                    }
                } else {
                    throw new RouteError("Unknown option or missing value: " . $key);
                }
            }
            $adding = $command === "add";
            foreach (\array_keys($options) as $key) {
                if (!$adding && !\in_array($key, ["--project", "--json"], true)) {
                    throw new RouteError("Editing options belong to add");
                }
            }
            foreach (["--expected-plan-sha256", "--expected-routes-sha256"] as $guard) {
                if (isset($options[$guard]) && (isset($options["--dry-run"]) || \preg_match("/\\A[a-f0-9]{64}\\z/D", $options[$guard]) !== 1)) {
                    throw new RouteError($guard . " requires application (no --dry-run) and a lowercase SHA-256");
                }
            }
            if ($command === "add" && isset($options["--expected-routes-sha256"])) {
                throw new RouteError("Use --expected-plan-sha256: additions must guard all configuration and endpoint inputs");
            }
            if ($adding && $json && !isset($options["--dry-run"]) && !isset($options["--expected-plan-sha256"])) {
                throw new RouteError("--json requires --dry-run for a preview, or --expected-plan-sha256 to apply a reviewed plan.");
            }
            $root = \realpath($options["--project"] ?? \getcwd());
            if ($root === false || !\is_dir($root)) {
                throw new RouteError("Project directory does not exist");
            }
            if ($adding && !isset($options["--path"])) {
                if ($json || !\stream_isatty(STDIN) || !\stream_isatty(STDOUT)) throw new RouteError("Supply --path with the complete route schema; filenames cannot determine constraints or optionality");
                $suggestion = "";
                if (isset($options["--endpoint"])) {
                    RoutePath::endpoint($options["--endpoint"]);
                    if (\preg_match('~\Aweb/([A-Z_-]+)/(.*)\.php\z~D', $options["--endpoint"], $match)) {
                        $options["--method"] ??= [$match[1]];
                        $suggestion = $match[2] === "index" ? "/" : "/" . \preg_replace('/\[([a-z][A-Za-z0-9_]*)\]/', '{$1}', $match[2]);
                    }
                }
                echo "Route schema", $suggestion ? " [" . $suggestion . "]" : "", ": ";
                $answer = \fgets(STDIN);
                if ($answer === false) throw new RouteError("Cancelled: no route schema supplied");
                $options["--path"] = \trim($answer) ?: $suggestion;
                echo "Method [", $options["--method"][0] ?? "GET", "]: ";
                $answer = \fgets(STDIN);
                if ($answer === false) throw new RouteError("Cancelled: no method supplied");
                if (\trim($answer) !== "") $options["--method"] = [\trim($answer)];
                if (\str_contains($options["--path"], "{")) {
                    foreach (["--constraint" => "Constraints (NAME=REGEX)", "--choices" => "Choices (NAME=a,b)", "--unused" => "Unused by every method (names)"] as $key => $label) {
                        if (isset($options[$key])) continue;
                        echo $label, " [none; space-separated]: ";
                        $answer = \fgets(STDIN);
                        if ($answer === false) throw new RouteError("Cancelled: incomplete route schema");
                        if (\trim($answer) !== "") $options[$key] = \preg_split('/\s+/', \trim($answer));
                    }
                }
            }
            $inventory = RoutingSources::read($root, readOnly: !$adding);
            $result["routingSources"] = $inventory["routingSources"];
            $sourceFile = $inventory["routingSources"][0]["file"];
            $result["routes"] = $inventory["routes"];
            $result["diagnostics"] = $inventory["diagnostics"];
            if ($inventory["diagnostics"]) {
                $result["message"] = "Incomplete inventory: unsupported configuration. No edits are permitted.";
                self::outputResult($result, $json, root: \is_string($root ?? null) ? $root : null);
                return 1;
            }
            if ($command === "list") {
                if (\count($inventory["routingSources"]) === 2 && $inventory["checks"]) {
                    $result["diagnostics"] = $inventory["checks"];
                    $result["message"] = "Inventories differ; synchronized edits are blocked.";
                    self::outputResult($result, $json, root: \is_string($root ?? null) ? $root : null);
                    return 1;
                }
                $result["message"] = "Inventory derived from native routes and available method files. Run check to verify discovered endpoints. Cross-server equivalence is required separately before editing. Validate the full server configuration through your environment.";
            } else {
                if ($inventory["checks"]) {
                    $result["diagnostics"] = $inventory["checks"];
                    $result["message"] = "Routing contract failed; no edits are permitted.";
                    self::outputResult($result, $json, root: \is_string($root ?? null) ? $root : null);
                    return 1;
                }
                self::validateTargets($root, \array_filter($inventory["routes"], static fn($route) => $route["kind"] === "endpoint"));
                if ($command === "check") {
                    $result["message"] = "Discovered route targets and PHP endpoint syntax passed. Full server configuration, PHP integration and HTTP behavior must be checked in your environment.";
                } else {
                    if (isset($options["--expected-routes-sha256"]) && !\hash_equals($options["--expected-routes-sha256"], \hash_file("sha256", $sourceFile))) {
                        throw new RouteError("Preview is stale: routes changed; preview again");
                    }
                    $plan = RouteEditor::preview($root, $options, $inventory);
                    if ($plan["inventory"]["diagnostics"] || $plan["inventory"]["checks"]) {
                        throw new RouteError("Proposed configuration cannot be fully interpreted");
                    }
                    if (isset($options["--expected-plan-sha256"]) && !\hash_equals($options["--expected-plan-sha256"], $plan["planSha256"])) {
                        throw new RouteError("Preview is stale: configuration or endpoint changed; preview again");
                    }
                    foreach ($plan["changes"] as $change) {
                        if ($change["action"] === "create") {
                            $code = \tempnam(\dirname($sourceFile), ".endpoint-check-");
                            $temporary[] = $code;
                            \file_put_contents($code, $change["text"]);
                            Process::run([PHP_BINARY, "-n", "-d", "short_open_tag=0", "-l", $code]);
                        }
                    }
                    foreach ($temporary as $file) {
                        if (\is_file($file)) \unlink($file);
                    }
                    $result["routes"] = $plan["inventory"]["routes"];
                    $result["changes"] = $plan["changes"];
                    $result["preconditions"] = $plan["preconditions"];
                    $result["planSha256"] = $plan["planSha256"];
                    $result["endpoint"] = $plan["endpoint"];
                    $result["message"] = "Dry run only. Run add without --dry-run to review and confirm these changes. Created endpoints return 501; existing PHP is preserved.";
                    if (!isset($options["--dry-run"])) {
                        $confirmed = true;
                        if (!isset($options["--expected-plan-sha256"])) {
                            $confirmed = CliConfirmation::confirm($plan["changes"]);
                            $previewShown = true;
                        }
                        if ($confirmed) {
                            RouteEditor::apply($root, $plan);
                            $result["message"] = "Associations applied. No service reloaded. Activate the updated routes through your environment workflow.";
                        } else {
                            $result["message"] = "Cancelled. No files changed.";
                        }
                    }
                }
            }
            $result["ok"] = true;
        } catch (Throwable $error) {
            $result["changes"] = [];
            $result["preconditions"] = [];
            $result["planSha256"] = null;
            $result["endpoint"] = null;
            $result["diagnostics"][] = ["file" => $error instanceof RouteError ? ($error->sourceFile ?? $sourceFile) : $sourceFile, "line" => $error instanceof RouteError ? $error->sourceLine : 1, "message" => $error->getMessage()];
        } finally {
            foreach ($temporary as $file) {
                if (\is_file($file)) {
                    \unlink($file);
                }
            }
        }
        self::outputResult($result, $json, $previewShown, \is_string($root ?? null) ? $root : null);
        return $result["ok"] ? 0 : 1;
    }
}
