<?php

declare(strict_types=1);

namespace Unphramed\Tools;

final class DiCommand
{
    public static function run(array $argv): int
    {
        $json = \in_array("--json", $argv, true);
        $root = \getcwd();
        $result = ["version" => 1, "operation" => "generate", "ok" => false, "graph" => [], "diagnostics" => [], "changes" => [], "preconditions" => [], "planSha256" => null, "message" => ""];

        if (!\in_array("--help", $argv, true) && (($argv[1] ?? null) === null || ($argv[1] ?? null) === "--project") && \stream_isatty(STDIN) && \stream_isatty(STDOUT)) {
            return DiInteractive::run(\array_slice($argv, 1));
        }

        if (\count($argv) === 1 || \in_array("--help", $argv, true)) {
            ConsoleOutput::diHelp($json);
            return 0;
        }

        try {
            if (($argv[1] ?? "") !== "generate") {
                throw new \InvalidArgumentException("Expected generate command.");
            }

            $options = ["class" => "App\\DI", "target" => "src/DI.php", "expose" => [], "shared" => [], "name" => [], "factory" => []];
            $apply = false;
            $expected = null;
            $seen = [];

            for ($i = 2; $i < \count($argv); $i++) {
                $flag = $argv[$i];

                if ($flag === "--json") {
                    continue;
                }

                if ($flag === "--apply") {
                    $apply = true;
                    continue;
                }

                if (!\in_array($flag, ["--project", "--class", "--target", "--expose", "--shared", "--name", "--factory", "--expected-plan-sha256"], true)) {
                    throw new \InvalidArgumentException("Unknown option $flag");
                }

                $value = $argv[++$i] ?? "";

                if ($value === "" || \str_starts_with($value, "--")) {
                    throw new \InvalidArgumentException("Missing value for $flag");
                }

                if (\in_array($flag, ["--project", "--class", "--target", "--expected-plan-sha256"], true) && isset($seen[$flag])) {
                    throw new \InvalidArgumentException("Duplicate option $flag");
                }

                $seen[$flag] = true;
                $key = \substr($flag, 2);

                if ($flag === "--project") {
                    $root = $value;
                }
                elseif ($flag === "--expected-plan-sha256") {
                    $expected = $value;
                }
                elseif (\in_array($key, ["expose", "shared"], true)) {
                    $options[$key][] = self::type($value);
                }
                elseif (\in_array($key, ["name", "factory"], true)) {
                    [$type, $method] = \array_pad(\explode("=", $value, 2), 2, "");
                    $type = self::type($type);

                    if (!\preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $method) || \str_starts_with($method, "__") || isset($options[$key][$type])) {
                        throw new \InvalidArgumentException("Invalid or duplicate $flag mapping.");
                    }

                    $options[$key][$type] = $method;
                } else {
                    $options[$key] = $key === "class" ? self::type($value) : $value;
                }
            }

            $root = \realpath($root);

            if ($root === false || !\is_dir($root)) {
                throw new \InvalidArgumentException("Project directory unavailable.");
            }

            if (!$options["expose"]) {
                throw new \InvalidArgumentException("Select at least one --expose class.");
            }

            foreach (["expose", "shared"] as $key) {
                $options[$key] = \array_values(\array_unique($options[$key]));
                \sort($options[$key]);
            }

            \ksort($options["name"]);
            \ksort($options["factory"]);

            if ($apply && $expected === null) {
                throw new \InvalidArgumentException("--apply requires --expected-plan-sha256 from a successful preview.");
            }

            if (!$apply && $expected !== null) {
                throw new \InvalidArgumentException("An expected plan hash requires --apply.");
            }

            $sources = new DiSources($root);
            $result = (new DiGenerator($sources, $options))->plan();

            if ($apply && $result["ok"]) {
                DiGenerator::apply($sources, $result, $expected);
                $result["message"] = "Explicit PHP factories applied. Review and test the application wiring.";
            }
        } catch (\Throwable $e) {
            $result["ok"] = false;
            $result["changes"] = [];
            $result["planSha256"] = null;
            $result["diagnostics"][] = ["file" => $e instanceof DiDiagnostic ? $e->source : ($root ?: \getcwd()), "line" => $e instanceof DiDiagnostic ? $e->sourceLine : 1, "message" => $e->getMessage()];
            $result["message"] = "Dependency assembly could not be generated.";
        }

        if ($json) {
            echo \json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        else {
            ConsoleOutput::diagnostics($result["diagnostics"]);
            ConsoleOutput::factories($result["graph"]);
            ConsoleOutput::changes($result["changes"]);
            echo $result["message"] . "\n";

            if ($result["planSha256"]) {
                echo "Plan SHA-256: " . $result["planSha256"] . "\n";
            }
        }

        return $result["ok"] ? 0 : 1;
    }

    private static function type(string $value): string
    {
        $value = \ltrim($value, "\\");

        if (!\preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $value)) {
            throw new \InvalidArgumentException("Invalid fully qualified type name: $value");
        }

        return $value;
    }
}
