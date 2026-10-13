<?php

declare(strict_types=1);

namespace Unphramed\Tools;

/** A lexer/parser for blocks, directives, strings and comments, not a regex file rewriter. */
final class NginxSyntax
{
    private array $tokens = [];
    private int $position = 0;
    private array $comments = [];

    public function __construct(string $source)
    {
        $length = strlen($source);
        for ($i = 0; $i < $length;) {
            if (ctype_space($source[$i])) {
                $i++;
                continue;
            }
            if ($source[$i] === '#') {
                $end = strpos($source, "\n", $i);
                $end = $end === false ? $length : $end;
                $this->comments[] = substr($source, $i, $end - $i);
                $i = $end;
                continue;
            }
            $start = $i;
            $line = substr_count(substr($source, 0, $i), "\n") + 1;
            $value = '';
            $kind = 'word';
            if (str_contains('{};', $source[$i])) {
                $kind = $value = $source[$i++];
            } elseif ($source[$i] === '"' || $source[$i] === "'") {
                $quote = $source[$i++];
                while ($i < $length && $source[$i] !== $quote) {
                    if ($source[$i] === '\\' && $i + 1 < $length) {
                        // Decode only escaped quotes/backslashes; retain regex escapes.
                        if ($source[$i + 1] === $quote || $source[$i + 1] === '\\') {
                            $i++;
                        }
                    }
                    $value .= $source[$i++];
                }
                if ($i === $length) {
                    throw new RouteError('Unterminated quoted string', $line);
                }
                $i++;
            } else {
                while ($i < $length && !ctype_space($source[$i]) && !str_contains('{};#', $source[$i])) {
                    if ($source[$i] === '"' || $source[$i] === "'") {
                        throw new RouteError('Mixed quoting or escaping outside the supported profile', $line);
                    }
                    $value .= $source[$i++];
                }
            }
            $this->tokens[] = ['value' => $value, 'kind' => $kind, 'line' => $line, 'start' => $start, 'end' => $i];
        }
    }

    public function comments(): array
    {
        return $this->comments;
    }

    public function parse(bool $nested = false): array
    {
        $nodes = [];
        while ($this->position < count($this->tokens)) {
            $first = $this->tokens[$this->position];
            if ($first['kind'] === '}') {
                if (!$nested) {
                    throw new RouteError('Unexpected closing brace', $first['line']);
                }
                $this->position++;
                return $nodes;
            }
            $args = [];
            while (isset($this->tokens[$this->position]) && $this->tokens[$this->position]['kind'] === 'word') {
                $args[] = $this->tokens[$this->position++]['value'];
            }
            $delimiter = $this->tokens[$this->position++] ?? null;
            if (!$args || !$delimiter || !in_array($delimiter['kind'], ['{', ';'], true)) {
                throw new RouteError('Expected a directive or block', $first['line']);
            }
            $children = $delimiter['kind'] === '{' ? $this->parse(true) : null;
            $nodes[] = ['args' => $args, 'children' => $children, 'line' => $first['line'], 'start' => $first['start'], 'end' => $this->tokens[$this->position - 1]['end']];
        }
        if ($nested) {
            throw new RouteError('Unclosed block', $this->tokens[count($this->tokens) - 1]['line']);
        }
        return $nodes;
    }

    public static function shape(array $nodes): array
    {
        return array_map(static fn(array $node): array => [$node['args'], $node['children'] === null ? null : self::shape($node['children'])], $nodes);
    }
}
