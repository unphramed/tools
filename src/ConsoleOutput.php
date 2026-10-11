<?php

declare(strict_types=1);

namespace Unphramed\Tools;

final class ConsoleOutput
{
    public static function style(string $text, string $code, bool $color = true): string
    {
        if (!$color || !stream_isatty(STDOUT) || getenv('NO_COLOR') !== false || getenv('TERM') === 'dumb') {
            return $text;
        }
        return "\033[" . $code . 'm' . $text . "\033[0m";
    }

    /** @param list<string> $headers @param list<list<string>> $rows */
    public static function table(array $headers, array $rows, bool $color = true): void
    {
        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $column => $cell) $widths[$column] = max($widths[$column], strlen($cell));
        }
        foreach ([$headers, ...$rows] as $index => $row) {
            $cells = [];
            foreach ($row as $column => $cell) {
                $cells[] = $column === count($row) - 1 ? $cell : str_pad($cell, $widths[$column]);
            }
            $line = implode('  ', $cells);
            echo $index === 0 ? self::style($line, '1;34', $color) : $line, "\n";
        }
    }

    public static function help(bool $json = false): void
    {
        $heading = static fn(string $text, string $code): string => self::style($text, $code, !$json);
        echo $heading('Unphramed Routes', '1;34'), "\n\n";
        echo $heading('Usage:', '1'), "\n  vendor/bin/unphramed-routes <command> [options]\n\n";
        echo $heading('Commands:', '1'), "\n";
        self::table(['  COMMAND', 'DESCRIPTION'], [
            ['  list', 'List configured routes'],
            ['  check', 'Validate routing configuration'],
            ['  add', 'Preview and confirm a route addition'],
        ], !$json);
        echo "\n", $heading('Examples:', '1'), "\n";
        echo "  vendor/bin/unphramed-routes add --path /users\n";
        echo "  vendor/bin/unphramed-routes add --path /users --dry-run\n\n";
        echo $heading('Options:', '1'), "\n";
        self::table(['  OPTION', 'DESCRIPTION'], [
            ['  --path PATH', 'Public route template (required for add)'],
            ['  --method METHOD', 'HTTP method (default: GET); repeat for multiple methods'],
            ['  --endpoint FILE', 'Override the inferred PHP endpoint under web/'],
            ['  --dry-run', 'Preview without writing (add only)'],
            ['  --project DIR', 'Target another project'],
            ['  --json', 'Output JSON'],
            ['  --help', 'Show this help'],
        ], !$json);
        echo "\n", $heading('Advanced options (add only):', '1'), "\n";
        self::table(['  OPTION', 'DESCRIPTION'], [
            ['  --expected-plan-sha256 HASH', 'Apply a reviewed plan without prompting'],
            ['  --fastcgi-pass VALUE', 'FastCGI destination when it cannot be inferred'],
            ['  --params-include VALUE', 'Parameter include when it cannot be inferred'],
            ['  --create-endpoint', 'Require a new 501 skeleton; refuse an existing file'],
        ], !$json);
        echo "\nGET is the default; selecting another method never adds GET or HEAD.\n";
        echo "Endpoints are inferred; absent files become 501 skeletons, existing PHP is preserved.\n";
        echo "add shows a diff and asks for confirmation; --dry-run only previews.\n";
        echo "Scripts/JSON: preview with --dry-run --json, then apply with --expected-plan-sha256 HASH.\n";
    }
}
