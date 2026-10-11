<?php

declare(strict_types=1);

namespace Unphramed\Tools;

final class DiDiagnostic extends \RuntimeException
{
    public function __construct(string $message, public readonly string $source, public readonly int $sourceLine = 1)
    {
        parent::__construct($message);
    }
}
