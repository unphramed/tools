<?php

declare(strict_types=1);

namespace Unphramed\Tools;

final class CliConfirmation
{
    /** @param list<array{file: string, action: string, text: string}> $changes */
    public static function confirm(array $changes): bool
    {
        if (!\stream_isatty(STDIN) || !\stream_isatty(STDOUT)) {
            throw new \RuntimeException("Interactive application requires a terminal. For scripts, preview with --dry-run --json, then pass --expected-plan-sha256 HASH.");
        }
        foreach ($changes as $change) {
            $before = $change["action"] === "create" ? "" : \file_get_contents($change["file"]);
            if ($before === false) throw new \RuntimeException("Cannot read preview input: " . $change["file"]);
            if ($before === $change["text"]) continue;
            $old = $before === "" ? [] : \explode("\n", \str_ends_with($before, "\n") ? \substr($before, 0, -1) : $before);
            $new = $change["text"] === "" ? [] : \explode("\n", \str_ends_with($change["text"], "\n") ? \substr($change["text"], 0, -1) : $change["text"]);
            $prefix = 0;
            while (isset($old[$prefix], $new[$prefix]) && $old[$prefix] === $new[$prefix]) ++$prefix;
            $suffix = 0;
            while ($suffix < \count($old) - $prefix && $suffix < \count($new) - $prefix
                && $old[\count($old) - $suffix - 1] === $new[\count($new) - $suffix - 1]) ++$suffix;
            $start = \max(0, $prefix - 3);
            $oldEnd = \min(\count($old), \count($old) - $suffix + 3);
            $newEnd = \min(\count($new), \count($new) - $suffix + 3);
            echo "\n", ConsoleOutput::style("--- " . ($change["action"] === "create" ? "/dev/null" : $change["file"]), "1"), "\n";
            echo ConsoleOutput::style("+++ " . $change["file"], "1"), "\n";
            echo "@@ -", $old ? $start + 1 : 0, ",", $oldEnd - $start, " +", $new ? $start + 1 : 0, ",", $newEnd - $start, " @@\n";
            foreach (\array_slice($old, $start, $prefix - $start) as $line) echo " ", $line, "\n";
            foreach (\array_slice($old, $prefix, \count($old) - $suffix - $prefix) as $line) echo ConsoleOutput::style("-" . $line, "31"), "\n";
            foreach (\array_slice($new, $prefix, \count($new) - $suffix - $prefix) as $line) echo ConsoleOutput::style("+" . $line, "32"), "\n";
            foreach (\array_slice($new, \count($new) - $suffix, $newEnd - (\count($new) - $suffix)) as $line) echo " ", $line, "\n";
        }
        echo "\nApply these changes? [y/N] ";
        \fflush(STDOUT);
        $answer = \fgets(STDIN);
        return $answer !== false && \in_array(\strtolower(\trim($answer)), ["y", "yes"], true);
    }
}
