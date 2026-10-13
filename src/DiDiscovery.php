<?php

declare(strict_types=1);

namespace Unphramed\Tools;

use PhpParser\Node;

/** Application-only inventory; dependencies remain available through DiSources. */
final class DiDiscovery
{
    public static function read(DiSources $sources): array
    {
        $composer = \json_decode($sources->read($sources->root . "/composer.json"), true, flags: JSON_THROW_ON_ERROR);
        $directories = [];
        $prefixes = [];
        $developmentPrefixes = [];
        $excluded = [$sources->vendor];
        foreach (["psr-4", "psr-0"] as $kind) {
            foreach ($composer["autoload"][$kind] ?? [] as $prefix => $paths) {
                $prefixes[] = $prefix;
                foreach ((array) $paths as $path) $directories[] = $sources->path($path);
            }
            foreach ($composer["autoload-dev"][$kind] ?? [] as $prefix => $paths) {
                $developmentPrefixes[] = $prefix;
                foreach ((array) $paths as $path) $excluded[] = $sources->path($path);
            }
        }
        $excluded = \array_values(\array_diff($excluded, $directories));
        $excluded[] = $sources->vendor;
        $files = [];
        $diagnostics = [];
        $walk = function (string $directory) use (&$walk, &$files, &$diagnostics, $sources, $excluded): void {
            if (\in_array($directory, $excluded, true) || \str_starts_with($directory, $sources->vendor . "/")) return;
            try {
                $sources->path($directory);
                if (!\is_dir($directory)) throw new DiDiagnostic("Autoload source directory is missing.", $directory);
                $entries = \scandir($directory);
                if ($entries === false) throw new DiDiagnostic("Cannot read source directory.", $directory);
                foreach ($entries as $entry) {
                    if (\str_starts_with($entry, ".")) continue;
                    $file = $directory . "/" . $entry;
                    if (\is_link($file)) {
                        $diagnostics[] = ["file" => $file, "line" => 1, "message" => "Symlink sources are skipped."];
                    } elseif (\is_dir($file)) $walk($file);
                    elseif (\str_ends_with($file, ".php") && \is_file($file)) $files[$file] = true;
                }
            } catch (DiDiagnostic $error) {
                $diagnostics[] = self::diagnostic($error);
            }
        };
        foreach (\array_unique($directories) as $directory) $walk($directory);
        // A manually included DI.php at the project root need not be PSR-autoloaded.
        if (\is_file($sources->root . "/DI.php")) $files[$sources->root . "/DI.php"] = true;
        \ksort($files);
        $classes = [];
        $targets = [];
        $specificity = static fn(string $type, array $prefixes): int => \max([-1, ...\array_map("strlen", \array_filter($prefixes, static fn($prefix) => \str_starts_with($type, $prefix)))]);
        foreach (\array_keys($files) as $file) {
            try {
                foreach (DiSources::declarations($sources->parse($file)) as $node) {
                    if (!$node instanceof Node\Stmt\Class_ || !isset($node->namespacedName)) continue;
                    $type = $node->namespacedName->toString();
                    $mainMatch = $specificity($type, $prefixes);
                    $applicationClass = $mainMatch >= 0 && $mainMatch >= $specificity($type, $developmentPrefixes);
                    if ($node->name->toString() === "DI" && ($applicationClass || $file === $sources->root . "/DI.php")) {
                        $targets[] = ["class" => $type, "target" => \substr($file, \strlen($sources->root) + 1)];
                    } elseif (!$node->isAbstract() && $applicationClass) {
                        [$declaration, $declaredFile] = $sources->declaration($type);
                        if ($declaredFile !== $file) throw new DiDiagnostic("$type does not match its Composer source path.", $file, $node->getStartLine());
                        $classes[$type] = ["file" => $file, "line" => $declaration->getStartLine()];
                    }
                }
            } catch (DiDiagnostic $error) {
                $diagnostics[] = self::diagnostic($error);
            }
        }
        \ksort($classes);
        return ["classes" => $classes, "targets" => $targets, "diagnostics" => $diagnostics];
    }

    public static function diagnostic(DiDiagnostic $error): array
    {
        return ["file" => $error->source, "line" => $error->sourceLine, "message" => $error->getMessage()];
    }
}
