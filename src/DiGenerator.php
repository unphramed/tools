<?php

declare(strict_types=1);

namespace Unphramed\Tools;

use PhpParser\Node;

final class DiGenerator
{
    private array $graph = [];
    private array $visiting = [];
    private array $diagnostics = [];
    private array $methods = [];
    private array $generated = [];
    private array $names = [];
    private ?Node\Stmt\Class_ $targetClass = null;
    private ?string $before;
    private string $target;

    public function __construct(private DiSources $sources, private array $options)
    {
        $this->target = $sources->path($options['target']);
        if (!\str_ends_with($this->target, '.php') || \str_starts_with($this->target, $sources->vendor . '/')) throw new DiDiagnostic('Select an application PHP file outside vendor.', $this->target);
        if (!\is_dir(\dirname($this->target))) throw new DiDiagnostic('Create the destination directory first.', $this->target);
        $this->before = $sources->read($this->target, true);
        if ($this->before !== null) {
            foreach (DiSources::declarations($sources->parse($this->target)) as $node) {
                if ($node instanceof Node\Stmt\Class_ && isset($node->namespacedName) && $node->namespacedName->toString() === $options['class']) $this->targetClass = $node;
            }
            // An abstract DI class can deliberately prevent instantiation while
            // providing concrete static factories, as well as a final class can.
            if (!$this->targetClass || $this->targetClass->extends || $this->targetClass->getTraitUses()) throw new DiDiagnostic('Destination must contain the selected DI class without inheritance or traits.', $this->target);
            foreach ($this->targetClass->getMethods() as $method) $this->methods[\strtolower($method->name->toString())] = $method;
        }
    }

    private function methodName(string $type): string
    {
        $name = $this->options['name'][$type] ?? 'get' . \substr($type, (int) \strrpos('\\' . $type, '\\'));
        if (!\preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) || \str_starts_with($name, '__')) throw new DiDiagnostic("Invalid factory method name: $name", $this->target);
        return $name;
    }

    /** Only declared, callable, typed factories are candidates; their bodies stay opaque. */
    public function existingFactories(string $type): array
    {
        $candidates = [];
        foreach ($this->methods as $method) {
            if (!$method->isStatic() || $method->isAbstract() || $method->byRef || !$method->returnType instanceof Node\Name) continue;
            foreach ($method->params as $param) if ($param->default === null && !$param->variadic) continue 2;
            try {
                $return = $this->sources->classType($method->returnType, $this->options['class'], $this->target);
                if ($this->sources->compatible($return, $type)) $candidates[] = $method->name->toString();
            } catch (DiDiagnostic) {
                // An unrelated return type may use unsupported autoloading. It is
                // not a proven match; explicit --factory still validates it fully.
            }
        }
        \sort($candidates, SORT_STRING);
        return $candidates;
    }

    private function visit(string $type, array $chain = []): string
    {
        if (isset($this->visiting[$type])) throw new DiDiagnostic('Constructor cycle: ' . \implode(' -> ', [...$chain, $type]), $this->graph[$type]['file'], $this->graph[$type]['line']);
        if (isset($this->graph[$type])) return $this->graph[$type]['method'];
        $candidates = $this->existingFactories($type);
        $manual = $this->options['factory'][$type] ?? null;
        $automatic = $manual === null && !isset($this->options['name'][$type]);
        if ($automatic && \count($candidates) === 1) $manual = $candidates[0];
        $name = $manual ?? $this->methodName($type);
        $public = \in_array($type, $this->options['expose'], true);
        $shared = \in_array($type, $this->options['shared'], true);
        $this->graph[$type] = ['type' => $type, 'method' => $name, 'visibility' => $public ? 'public' : 'private', 'lifetime' => $manual ? 'manual' : ($shared ? 'shared' : 'new'), 'manual' => $manual !== null, 'candidates' => $candidates, 'dependencies' => [], 'file' => $this->target, 'line' => 1];
        if ($automatic && \count($candidates) > 1) throw new DiDiagnostic("Several existing factories return $type: " . \implode(', ', $candidates) . '. Select --factory TYPE=METHOD, or --name TYPE=NEW_METHOD to construct a separate graph.', $this->target);
        if ($manual !== null) {
            $method = $this->methods[\strtolower($manual)] ?? null;
            if (!$method || !$method->isStatic() || $method->isAbstract() || $method->byRef || ($public && !$method->isPublic())) throw new DiDiagnostic("Manual factory $manual must be a concrete static method" . ($public ? ' accessible publicly.' : '.'), $this->target, $method?->getStartLine() ?? 1);
            foreach ($method->params as $param) if ($param->default === null && !$param->variadic) throw new DiDiagnostic("Manual factory $manual has required arguments.", $this->target, $param->getStartLine());
            $return = $method->returnType;
            if (!$return instanceof Node\Name || !$this->sources->compatible($this->sources->classType($return, $this->options['class'], $this->target), $type)) throw new DiDiagnostic("Manual factory $manual needs a compatible, non-null class/interface return type.", $this->target, $method->getStartLine());
            if ($shared || isset($this->options['name'][$type])) throw new DiDiagnostic("$type uses a manual factory: its name and lifetime are controlled by its existing PHP.", $this->target, $method->getStartLine());
            $this->graph[$type]['visibility'] = $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private');
            $this->graph[$type]['line'] = $method->getStartLine();
            return $manual;
        }
        if (isset($this->methods[\strtolower($name)])) throw new DiDiagnostic("Method $name already exists. Select it explicitly with --factory or choose another --name; it will not be overwritten.", $this->target, $this->methods[\strtolower($name)]->getStartLine());
        if (isset($this->names[\strtolower($name)])) throw new DiDiagnostic("Method name collision: $name. Choose distinct --name values.", $this->target);
        $this->names[\strtolower($name)] = $type;
        [$class, $file] = $this->sources->declaration($type);
        $this->graph[$type]['file'] = $file;
        $this->graph[$type]['line'] = $class->getStartLine();
        $this->visiting[$type] = true;
        $arguments = [];
        $constructor = $this->sources->constructor($type);
        if ($constructor !== null) {
            [$method, $constructorFile, $declaring] = $constructor;
            foreach ($method->params as $param) {
                if ($param->default !== null) continue;
                if ($param->variadic || $param->byRef || $param->type === null) throw new DiDiagnostic("$type has an untyped, variadic or by-reference parameter. Select a manual factory.", $constructorFile, $param->getStartLine());
                $dependency = $this->sources->classType($param->type, $declaring, $constructorFile);
                $this->graph[$type]['dependencies'][] = $dependency;
                $arguments[] = $param->var->name . ': self::' . $this->visit($dependency, [...$chain, $type]) . '()';
            }
        }
        unset($this->visiting[$type]);
        $construction = 'new \\' . $type . '(' . \implode(', ', $arguments) . ')';
        $body = $shared ? "        static \$instance = null;\n\n        return \$instance ??= $construction;" : "        return $construction;";
        $this->generated[$type] = '    ' . ($public ? 'public' : 'private') . " static function $name(): \\$type\n    {\n$body\n    }\n";
        return $name;
    }

    public function plan(): array
    {
        foreach ($this->options['expose'] as $type) {
            try { $this->visit($type); }
            catch (DiDiagnostic $e) { $this->diagnostics[] = ['file' => $e->source, 'line' => $e->sourceLine, 'message' => $e->getMessage()]; }
        }
        foreach (\array_unique([...$this->options['shared'], ...\array_keys($this->options['name']), ...\array_keys($this->options['factory'])]) as $type) {
            if (!isset($this->graph[$type])) $this->diagnostics[] = ['file' => $this->target, 'line' => 1, 'message' => "Unused choice for $type; select a reachable dependency."];
        }
        $changes = [];
        if ($this->diagnostics === [] && $this->generated !== []) {
            // Public entry points first; private dependencies follow in discovery order.
            $ordered = \array_values(\array_filter(\array_keys($this->graph), fn($type) => isset($this->generated[$type])));
            \usort($ordered, fn($a, $b) => (int) \in_array($b, $this->options['expose'], true) <=> (int) \in_array($a, $this->options['expose'], true));
            $methods = \implode("\n", \array_map(fn($type) => $this->generated[$type], $ordered));
            if ($this->before === null) {
                $parts = \explode('\\', $this->options['class']);
                $short = \array_pop($parts);
                $namespace = $parts ? 'namespace ' . \implode('\\', $parts) . ";\n\n" : '';
                $after = "<?php\n\ndeclare(strict_types=1);\n\n{$namespace}final class $short\n{\n$methods}\n";
            } else {
                $position = $this->targetClass->getEndFilePos();
                $eol = \str_contains($this->before, "\r\n") ? "\r\n" : "\n";
                $after = \substr($this->before, 0, $position) . $eol . \str_replace("\n", $eol, $methods) . \substr($this->before, $position);
            }
            // Validate the output without executing it.
            (new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse($after);
            $changes[] = ['file' => $this->target, 'action' => $this->before === null ? 'create' : 'update', 'text' => $after, 'beforeSha256' => $this->sources->inputs[$this->target]];
        }
        \ksort($this->sources->inputs);
        $preconditions = [];
        foreach ($this->sources->inputs as $file => $hash) $preconditions[] = ['file' => $file, 'sha256' => $hash];
        return ['version' => 1, 'operation' => 'generate', 'ok' => $this->diagnostics === [], 'graph' => \array_values($this->graph), 'diagnostics' => $this->diagnostics, 'changes' => $changes, 'preconditions' => $preconditions,
            'planSha256' => $this->diagnostics ? null : \hash('sha256', \json_encode([$this->options, $changes, $preconditions], JSON_THROW_ON_ERROR)),
            'message' => $this->diagnostics ? 'Resolve the diagnostics or select manual factories; nothing can be applied.' : ($changes ? 'Explicit PHP factories previewed. Review before applying.' : 'Existing factories satisfy the selection; no changes needed.')];
    }

    public static function apply(DiSources $sources, array $plan, string $expected): void
    {
        if (!$plan['ok'] || !\hash_equals($plan['planSha256'], $expected)) throw new DiDiagnostic('Preview is stale or choices changed. Generate a new preview.', $sources->root);
        $handles = [];
        $created = false;
        $writeStarted = false;
        $change = $plan['changes'][0] ?? null;
        try {
            foreach ($plan['preconditions'] as $input) {
                $path = $sources->path($input['file']);
                if ($input['sha256'] === null) {
                    if (\file_exists($path)) throw new DiDiagnostic('Preview is stale: a previously absent input now exists.', $path);
                    continue;
                }
                $handle = @\fopen($path, $change && $path === $change['file'] ? 'r+b' : 'rb');
                if (!$handle) throw new DiDiagnostic('Cannot open input.', $path);
                $handles[$path] = $handle;
                if (!\flock($handle, $change && $path === $change['file'] ? LOCK_EX : LOCK_SH) || \hash('sha256', \stream_get_contents($handle)) !== $input['sha256']) throw new DiDiagnostic('Preview is stale or input cannot be locked.', $path);
            }
            if (!$change) return;
            $target = $sources->path($change['file']);
            $before = $change['action'] === 'update' ? \file_get_contents($target) : null;
            $handle = $handles[$target] ?? @\fopen($target, 'x+b');
            if (!$handle) throw new DiDiagnostic('Cannot create destination without overwriting.', $target);
            if (!isset($handles[$target])) { $handles[$target] = $handle; $created = true; }
            $writeStarted = true;
            \rewind($handle);
            if (!\ftruncate($handle, 0) || \fwrite($handle, $change['text']) !== \strlen($change['text']) || !\fflush($handle)) throw new DiDiagnostic('Writing DI failed.', $target);
        } catch (\Throwable $e) {
            if ($writeStarted && !$created && isset($before, $handles[$change['file']])) {
                $handle = $handles[$change['file']];
                \rewind($handle); \ftruncate($handle, 0); \fwrite($handle, $before); \fflush($handle);
            }
            if ($created) \unlink($change['file']);
            throw $e;
        } finally {
            foreach ($handles as $handle) { \flock($handle, LOCK_UN); \fclose($handle); }
        }
    }
}
