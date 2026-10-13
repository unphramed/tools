<?php

declare(strict_types=1);

namespace Unphramed\Tools;

final class DiInteractive
{
    public static function run(array $arguments): int
    {
        try {
            if ($arguments !== [] && (\count($arguments) !== 2 || $arguments[0] !== '--project')) throw new \InvalidArgumentException('Use unphramed-di [--project DIR], or --help for advanced generation.');
            $root = \realpath($arguments[1] ?? \getcwd());
            if ($root === false || !\is_dir($root)) throw new \InvalidArgumentException('Project directory unavailable.');
            $sources = new DiSources($root);
            echo ConsoleOutput::style('Unphramed DI', '1;34'), "\n\n";
            $inventory = DiDiscovery::read($sources);
            self::diagnostics($inventory['diagnostics']);
            $targets = $inventory['targets'];
            if (\count($targets) === 1) $target = $targets[0];
            elseif ($targets) {
                ConsoleOutput::table(['NUMBER', 'DI CLASS', 'FILE'], \array_map(static fn($i, $target) => [(string) ($i + 1), $target['class'], $target['target']], \array_keys($targets), $targets));
                while (true) {
                    $answer = self::ask('Choose the DI class number (q to cancel): ');
                    if ($answer === null) return self::cancel();
                    if (\ctype_digit($answer) && isset($targets[(int) $answer - 1])) { $target = $targets[(int) $answer - 1]; break; }
                    echo "Choose one listed number.\n";
                }
            } else {
                echo "No DI class found. Choose the class and file to create or extend.\n";
                $class = self::ask('DI class (fully qualified; q to cancel): ');
                if ($class === null) return self::cancel();
                $file = self::ask('DI PHP file (relative to the project; q to cancel): ');
                if ($file === null) return self::cancel();
                if (!\preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D', \ltrim($class, '\\'))) throw new \InvalidArgumentException('Invalid DI class name.');
                $target = ['class' => \ltrim($class, '\\'), 'target' => $file];
            }
            echo 'Destination: ', $target['class'], ' in ', $target['target'], "\n";
            $options = $target + ['expose' => [], 'shared' => [], 'name' => [], 'factory' => []];
            $generator = new DiGenerator($sources, $options);
            $missing = [];
            $related = [];
            foreach (\array_keys($inventory['classes']) as $type) {
                if ($type === $target['class'] || $generator->existingFactories($type)) continue;
                $missing[] = $type;
                $links = DiSuggestions::related($sources, $generator, $type);
                if ($links) $related[$type] = $links;
            }
            $eligible = self::candidates(\array_keys($related), $sources, $options, true);
            if ($eligible) {
                echo "\nSuggested DI additions:\n";
                ConsoleOutput::table(['NUMBER', 'SUGGESTED CLASS', 'EXISTING DEPENDENCY'], \array_map(static function ($i, $type) use ($related) {
                    $dependency = \array_key_first($related[$type]);
                    return [(string) ($i + 1), $type, $dependency . ' via ' . \implode(', ', $related[$type][$dependency]) . '()'];
                }, \array_keys($eligible), $eligible));
                $selection = self::select('Classes to expose [all] (numbers, all, none, other, or q): ', $eligible, true, true);
            } else {
                echo "\nNo DI additions suggested.\n";
                $answer = self::ask('Show other constructible classes? [y/N] ');
                if ($answer === null) return self::cancel();
                if (!\in_array(\strtolower($answer), ['y', 'yes'], true)) { echo "No files changed.\n"; return 0; }
                $selection = 'other';
            }
            if ($selection === 'other') {
                $eligible = self::candidates($missing, $sources, $options, false);
                if (!$eligible) { echo "No other constructible classes available. No files changed.\n"; return 0; }
                echo "\nConstructible classes for explicit selection. Construction alone does not imply a useful DI factory.\n";
                ConsoleOutput::table(['NUMBER', 'CONSTRUCTIBLE CLASS'], \array_map(static fn($i, $type) => [(string) ($i + 1), $type], \array_keys($eligible), $eligible));
                $selection = self::select('Classes to expose [none] (numbers, all, none, or q): ', $eligible, false);
            }
            if ($selection === null || $selection === []) return self::cancel();
            $options['expose'] = $selection;
            $plan = (new DiGenerator($sources, $options))->plan();
            // Individually valid roots can conflict when combined. Never guess a name.
            if (!$plan['ok']) { self::diagnostics($plan['diagnostics']); echo "No files changed. Select fewer classes or resolve these choices with generate.\n"; return 1; }
            $newTypes = \array_column(\array_values(\array_filter($plan['graph'], static fn($node) => !$node['manual'])), 'type');
            ConsoleOutput::table(['NUMBER', 'NEW FACTORY TYPE'], \array_map(static fn($i, $type) => [(string) ($i + 1), $type], \array_keys($newTypes), $newTypes));
            $shared = self::select('Types to share [none] (numbers, all, none, or q): ', $newTypes, false);
            if ($shared === null) return self::cancel();
            $options['shared'] = $shared;
            $plan = (new DiGenerator($sources, $options))->plan();
            if (!$plan['ok']) { self::diagnostics($plan['diagnostics']); return 1; }
            echo "\nProposed factories:\n";
            ConsoleOutput::factories($plan['graph']);
            if (!$plan['changes']) { echo "No changes needed.\n"; return 0; }
            if (!CliConfirmation::confirm($plan['changes'])) return self::cancel();
            DiGenerator::apply($sources, $plan, $plan['planSha256']);
            echo "Explicit PHP factories applied. Review and test the application wiring.\n";
            return 0;
        } catch (\Throwable $error) {
            $message = $error instanceof DiDiagnostic ? $error->source . ':' . $error->sourceLine . ': ' . $error->getMessage() : $error->getMessage();
            echo ConsoleOutput::style($message, '31'), "\n";
            return 1;
        }
    }

    /** @return list<string> */
    private static function candidates(array $types, DiSources $sources, array $options, bool $diagnostics): array
    {
        $probes = [];
        $methodTypes = [];
        foreach ($types as $type) {
            $probe = (new DiGenerator($sources, \array_replace($options, ['expose' => [$type]])))->plan();
            if (!$probe['ok']) {
                if ($diagnostics) {
                    echo "\nNeeds manual configuration: $type\n";
                    self::diagnostics($probe['diagnostics']);
                }
                continue;
            }
            $probes[$type] = $probe;
            foreach ($probe['graph'] as $node) {
                if (!$node['manual']) $methodTypes[\strtolower($node['method'])][$node['type']] = true;
            }
        }
        $collisions = \array_filter($methodTypes, static fn($types) => \count($types) > 1);
        return \array_values(\array_filter(\array_keys($probes), static function ($type) use ($probes, $collisions, $diagnostics): bool {
            foreach ($probes[$type]['graph'] as $node) {
                if (!$node['manual'] && isset($collisions[\strtolower($node['method'])])) {
                    if ($diagnostics) echo "Needs manual configuration: $type (factory name collision: ", $node['method'], "). Use generate --name to choose a distinct name.\n";
                    return false;
                }
            }
            return true;
        }));
    }

    private static function ask(string $prompt): ?string
    {
        echo $prompt;
        \fflush(STDOUT);
        $answer = \fgets(STDIN);
        if ($answer === false || \strtolower(\trim($answer)) === 'q') return null;
        return \trim($answer);
    }

    /** @return list<string>|'other'|null */
    private static function select(string $prompt, array $choices, bool $all, bool $allowOther = false): array|string|null
    {
        while (true) {
            $answer = self::ask($prompt);
            if ($answer === null) return null;
            if ($allowOther && \strtolower($answer) === 'other') return 'other';
            if ($answer === '') return $all ? $choices : [];
            if (\strtolower($answer) === 'all') return $choices;
            if (\strtolower($answer) === 'none') return [];
            $selected = [];
            foreach (\preg_split('/[\s,]+/', $answer) as $number) {
                if (!\ctype_digit($number) || !isset($choices[(int) $number - 1])) { echo "Use listed numbers separated by commas or spaces.\n"; continue 2; }
                $selected[] = $choices[(int) $number - 1];
            }
            return \array_values(\array_unique($selected));
        }
    }

    private static function diagnostics(array $diagnostics): void
    {
        ConsoleOutput::diagnostics($diagnostics);
    }

    private static function cancel(): int
    {
        echo "Cancelled. No files changed.\n";
        return 0;
    }
}
