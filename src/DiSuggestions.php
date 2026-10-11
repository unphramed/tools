<?php

declare(strict_types=1);

namespace Unphramed\Tools;

use PhpParser\Node;

/** Evidence for suggestions, not a classification of application objects. */
final class DiSuggestions
{
    public static function related(DiSources $sources, DiGenerator $generator, string $type, array $visiting = []): array
    {
        if (in_array($type, $visiting, true)) return [];
        $factories = $generator->existingFactories($type);
        if ($factories) return [$type => $factories];
        try {
            $constructor = $sources->constructor($type);
        } catch (DiDiagnostic) {
            // Unsupported or unresolved construction is not evidence of a DI link.
            return [];
        }
        if ($constructor === null) return [];
        [$method, $file, $declaring] = $constructor;
        $related = [];
        foreach ($method->params as $parameter) {
            if ($parameter->default !== null) continue;
            $node = $parameter->type;
            if ($node instanceof Node\NullableType) $node = $node->type;
            if (!$node instanceof Node\Name) continue;
            try {
                $dependency = $sources->classType($node, $declaring, $file);
                $related += self::related($sources, $generator, $dependency, [...$visiting, $type]);
            } catch (DiDiagnostic) {
                // Keep looking: another parameter can provide a known DI link.
            }
        }
        ksort($related);
        return $related;
    }
}
