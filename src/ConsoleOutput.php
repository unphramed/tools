<?php

declare(strict_types=1);

namespace Unphramed\Tools;

final class ConsoleOutput
{
    public static function style(string $text, string $code, bool $color = true): string
    {
        if (!$color || !\stream_isatty(STDOUT) || \getenv("NO_COLOR") !== false || \getenv("TERM") === "dumb") {
            return $text;
        }
        return "\033[" . $code . "m" . $text . "\033[0m";
    }

    /** @param list<string> $headers @param list<list<string>> $rows */
    public static function table(array $headers, array $rows, bool $color = true): void
    {
        $widths = \array_map("strlen", $headers);
        foreach ($rows as $row) {
            foreach ($row as $column => $cell) $widths[$column] = \max($widths[$column], \strlen($cell));
        }
        foreach ([$headers, ...$rows] as $index => $row) {
            $cells = [];
            foreach ($row as $column => $cell) {
                $cells[] = $column === \count($row) - 1 ? $cell : \str_pad($cell, $widths[$column]);
            }
            $line = \implode("  ", $cells);
            echo $index === 0 ? self::style($line, "1;34", $color) : $line, "\n";
        }
    }

    public static function diagnostics(array $diagnostics): void
    {
        foreach ($diagnostics as $diagnostic) {
            echo self::style($diagnostic["file"] . ":" . $diagnostic["line"] . ": " . $diagnostic["message"], "31"), "\n";
        }
    }

    public static function changes(array $changes): void
    {
        foreach ($changes as $change) {
            echo "\n", self::style($change["action"] . " " . $change["file"], "1"), "\n";
            foreach (\explode("\n", \rtrim($change["text"], "\n")) as $line) echo self::style("+" . $line, "32"), "\n";
        }
    }

    public static function factories(array $graph): void
    {
        if (!$graph) return;
        self::table(["VISIBILITY", "METHOD", "TYPE", "LIFETIME", "SOURCE"], \array_map(static fn($node) => [
            $node["visibility"], $node["method"] . "()", $node["type"], $node["lifetime"], $node["file"] . ":" . $node["line"],
        ], $graph));
    }

    public static function diHelp(bool $json = false): void
    {
        $heading = static fn(string $text, string $code): string => self::style($text, $code, !$json);
        echo $heading("Unphramed DI", "1;34"), "\n\n";
        echo $heading("Usage:", "1"), "\n  vendor/bin/unphramed-di [--project DIR]\n";
        echo "  vendor/bin/unphramed-di generate [options]\n\n";
        echo $heading("Commands:", "1"), "\n";
        self::table(["  COMMAND", "DESCRIPTION"], [
            ["  (no command)", "Suggest DI-linked additions and confirm a diff in a terminal"],
            ["  generate", "Preview explicit dependency factories for scripts and IDE clients"],
        ], !$json);
        echo "\n", $heading("Examples:", "1"), "\n";
        echo "  vendor/bin/unphramed-di\n";
        echo "  vendor/bin/unphramed-di generate --expose 'App\\RatingSummary'\n";
        echo "  vendor/bin/unphramed-di generate --expose 'App\\RatingSummary' --json\n\n";
        echo $heading("Options:", "1"), "\n";
        self::table(["  OPTION", "DESCRIPTION"], [
            ["  --project DIR", "Target another project"],
            ["  --json", "Output JSON (generate only)"],
            ["  --help", "Show this help"],
        ], !$json);
        echo "\n", $heading("Advanced options (generate only):", "1"), "\n";
        self::table(["  OPTION", "DESCRIPTION"], [
            ["  --expose TYPE", "Public entry point (required); repeat for multiple types"],
            ["  --shared TYPE", "Share a new object; repeat for multiple types"],
            ["  --class TYPE", "DI class (default: App\\DI)"],
            ["  --target FILE", "Destination PHP file (default: src/DI.php)"],
            ["  --name TYPE=METHOD", "Choose a new factory method name"],
            ["  --factory TYPE=METHOD", "Select an existing typed factory"],
            ["  --apply", "Apply a reviewed plan with its expected hash"],
            ["  --expected-plan-sha256 HASH", "Required with --apply; obtained from a successful preview"],
        ], !$json);
        echo "\nThe terminal assistant shows a diff and asks for confirmation; Enter cancels.\n";
        echo "Without a terminal, the bare command shows help and never writes.\n";
        echo "generate only previews unless --apply --expected-plan-sha256 HASH is supplied.\n";
        echo "Existing factories are reused by type; their PHP and lifetimes are preserved.\n";
    }

    public static function help(bool $json = false): void
    {
        $heading = static fn(string $text, string $code): string => self::style($text, $code, !$json);
        echo $heading("Unphramed Routes", "1;34"), "\n\n";
        echo $heading("Usage:", "1"), "\n  vendor/bin/unphramed-routes <command> [options]\n\n";
        echo $heading("Commands:", "1"), "\n";
        self::table(["  COMMAND", "DESCRIPTION"], [
            ["  list", "List configured routes"],
            ["  check", "Validate routing configuration"],
            ["  add", "Preview and confirm a route addition"],
        ], !$json);
        echo "\n", $heading("Examples:", "1"), "\n";
        echo "  vendor/bin/unphramed-routes add /users\n";
        echo "  vendor/bin/unphramed-routes add /users --dry-run\n\n";
        echo $heading("Options:", "1"), "\n";
        self::table(["  OPTION", "DESCRIPTION"], [
            ["  --method METHOD", "HTTP method (default: GET); repeat for multiple methods"],
            ["  --endpoint FILE", "Override the inferred PHP endpoint under web/"],
            ["  --dry-run", "Preview without writing (add only)"],
            ["  --project DIR", "Target another project"],
            ["  --json", "Output JSON"],
            ["  --help", "Show this help"],
        ], !$json);
        echo "\n", $heading("Advanced options (add only):", "1"), "\n";
        self::table(["  OPTION", "DESCRIPTION"], [
            ["  --expected-plan-sha256 HASH", "Apply a reviewed plan without prompting"],
            ["  --create-endpoint", "Require a new 501 skeleton; refuse an existing file"],
        ], !$json);
        echo "\nUsage: unphramed-routes add ROUTE_PATTERN [options]\n";
        echo "\nGET is the default; inferred HEAD selects its GET file without creating a HEAD file.\n";
        echo "Endpoints are inferred; absent files become 501 skeletons, existing PHP is preserved.\n";
        echo "add shows a diff and asks for confirmation; --dry-run only previews.\n";
        echo "Scripts/JSON: preview with --dry-run --json, then apply with --expected-plan-sha256 HASH.\n";
    }
}
