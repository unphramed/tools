<?php

declare(strict_types=1);

namespace Unphramed\Tools;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Static Composer PSR-4/PSR-0 lookup. Never evaluates application or generated PHP. */
final class DiSources
{
    public array $inputs = [];
    public readonly string $vendor;
    private array $prefixes = [];
    private array $classes = [];
    private array $files = [];

    public function __construct(public readonly string $root)
    {
        $composer = \json_decode($this->read($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->mappings($composer['autoload']['psr-4'] ?? [], $root);
        $this->mappings($composer['autoload-dev']['psr-4'] ?? [], $root);
        $this->mappings($composer['autoload']['psr-0'] ?? [], $root, true);
        $this->mappings($composer['autoload-dev']['psr-0'] ?? [], $root, true);
        $vendor = $this->vendor = $this->path($composer['config']['vendor-dir'] ?? 'vendor');
        $installed = $vendor . '/composer/installed.json';
        $this->read($root . '/composer.lock', true);
        $text = $this->read($installed, true);
        if ($text !== null) {
            $data = \json_decode($text, true, flags: JSON_THROW_ON_ERROR);
            foreach ($data['packages'] ?? $data as $package) {
                $base = $this->path(\dirname($installed) . '/' . ($package['install-path'] ?? '../' . $package['name']));
                $this->mappings($package['autoload']['psr-4'] ?? [], $base);
                $this->mappings($package['autoload']['psr-0'] ?? [], $base, true);
            }
        }
    }

    private function mappings(array $mappings, string $base, bool $legacy = false): void
    {
        foreach ($mappings as $prefix => $paths) {
            foreach ((array) $paths as $path) $this->prefixes[] = [$prefix, $this->path($base . '/' . $path), $legacy];
        }
    }

    public function path(string $path): string
    {
        if (!\str_starts_with($path, '/')) $path = $this->root . '/' . $path;
        $parts = [];
        foreach (\explode('/', $path) as $part) {
            if ($part === '..') \array_pop($parts);
            elseif ($part !== '' && $part !== '.') $parts[] = $part;
        }
        $path = '/' . \implode('/', $parts);
        if ($path !== $this->root && !\str_starts_with($path, $this->root . '/')) throw new DiDiagnostic('Path must stay inside the project.', $path);
        $current = $this->root;
        foreach (\explode('/', \substr($path, \strlen($this->root) + 1)) as $part) {
            if ($part === '') continue;
            $current .= '/' . $part;
            if (\is_link($current)) throw new DiDiagnostic('Symlink paths are not supported.', $current);
        }
        return $path;
    }

    public function read(string $path, bool $optional = false): ?string
    {
        $path = $this->path($path);
        if (!\is_file($path)) {
            if (!$optional || \file_exists($path)) throw new DiDiagnostic('File is missing or is not a regular file.', $path);
            if (isset($this->inputs[$path])) throw new DiDiagnostic('Source disappeared during analysis. Generate a new preview.', $path);
            $this->inputs[$path] = null;
            return null;
        }
        $text = @\file_get_contents($path);
        if ($text === false) throw new DiDiagnostic('Cannot read file.', $path);
        $hash = \hash('sha256', $text);
        if (\array_key_exists($path, $this->inputs) && $this->inputs[$path] !== $hash) throw new DiDiagnostic('Source changed during analysis. Generate a new preview.', $path);
        $this->inputs[$path] = $hash;
        return $text;
    }

    public function parse(string $path, ?string $text = null): array
    {
        if (isset($this->files[$path])) return $this->files[$path];
        $text ??= $this->read($path);
        try {
            $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($text) ?? [];
            $traverser = new NodeTraverser(new NameResolver());
            $nodes = $traverser->traverse($nodes);
        } catch (\PhpParser\Error $e) {
            throw new DiDiagnostic($e->getRawMessage(), $path, \max(1, $e->getStartLine()));
        }
        return $this->files[$path] = $nodes;
    }

    public function declaration(string $name): array
    {
        if (isset($this->classes[$name])) return $this->classes[$name];
        $candidates = [];
        foreach ($this->prefixes as [$prefix, $directory, $legacy]) {
            if (!\str_starts_with($name, $prefix)) continue;
            if ($legacy) {
                // PSR-0 keeps the namespace prefix and converts underscores only
                // in the short class name, not in namespace components.
                $separator = \strrpos($name, '\\');
                $relative = $separator === false ? \str_replace('_', '/', $name)
                    : \str_replace('\\', '/', \substr($name, 0, $separator + 1)) . \str_replace('_', '/', \substr($name, $separator + 1));
            } else $relative = \str_replace('\\', '/', \substr($name, \strlen($prefix)));
            $file = $this->path($directory . '/' . $relative . '.php');
            if ($this->read($file, true) !== null) $candidates[$file] = true;
        }
        if (\count($candidates) !== 1) throw new DiDiagnostic("Expected one Composer PSR-4/PSR-0 source for $name; found " . \count($candidates) . '. Supply a manual factory for unsupported/internal types.', $this->root . '/composer.json');
        $file = \array_key_first($candidates);
        $declarations = self::declarations($this->parse($file));
        foreach ($declarations as $node) {
            if (isset($node->namespacedName) && $node->namespacedName->toString() === $name) {
                return $this->classes[$name] = [$node, $file];
            }
        }
        throw new DiDiagnostic("No declaration for $name in its Composer source file.", $file);
    }

    public static function declarations(array $nodes): array
    {
        $classes = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) \array_push($classes, ...self::declarations($node->stmts));
            elseif ($node instanceof Node\Stmt\ClassLike) $classes[] = $node;
        }
        return $classes;
    }

    public function constructor(string $name, array $chain = []): ?array
    {
        if (\in_array($name, $chain, true)) throw new DiDiagnostic('Cyclic inheritance: ' . \implode(' -> ', [...$chain, $name]), $this->root . '/composer.json');
        [$class, $file] = $this->declaration($name);
        if (!$class instanceof Node\Stmt\Class_ || ($class->isAbstract() && $chain === [])) throw new DiDiagnostic("$name is not a concrete class. Select a manual factory.", $file, $class->getStartLine());
        // Traits may introduce or alias constructors; never guess their effective behavior.
        if ($class->getTraitUses() !== []) throw new DiDiagnostic("$name uses traits. Select a manual factory for this version.", $file, $class->getStartLine());
        $constructor = $class->getMethod('__construct');
        if ($constructor !== null) {
            if (!$constructor->isPublic()) throw new DiDiagnostic("$name has a non-public constructor. Select a manual factory.", $file, $constructor->getStartLine());
            return [$constructor, $file, $name];
        }
        return $class->extends ? $this->constructor($class->extends->toString(), [...$chain, $name]) : null;
    }

    public function classType(Node $type, string $declaringClass, string $file): string
    {
        if ($type instanceof Node\NullableType) $type = $type->type;
        if (!$type instanceof Node\Name) throw new DiDiagnostic('Required scalar, untyped, union or intersection parameter needs a manual factory.', $file, $type->getStartLine());
        $name = $type->toString();
        if (\strtolower($name) === 'self') return $declaringClass;
        if (\strtolower($name) === 'parent') {
            [$class] = $this->declaration($declaringClass);
            if ($class instanceof Node\Stmt\Class_ && $class->extends) return $class->extends->toString();
            throw new DiDiagnostic('Cannot resolve parent type.', $file, $type->getStartLine());
        }
        return $name;
    }

    public function compatible(string $actual, string $expected, array $seen = []): bool
    {
        if (\strcasecmp($actual, $expected) === 0) return true;
        if (\in_array($actual, $seen, true)) return false;
        [$node] = $this->declaration($actual);
        $parents = $node instanceof Node\Stmt\Class_ ? [...($node->extends ? [$node->extends] : []), ...$node->implements]
            : ($node instanceof Node\Stmt\Interface_ ? $node->extends : []);
        foreach ($parents as $parent) if ($this->compatible($parent->toString(), $expected, [...$seen, $actual])) return true;
        return false;
    }
}
