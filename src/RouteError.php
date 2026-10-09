<?php

declare(strict_types=1);

namespace Unphramed\Tools;

use RuntimeException;

final class RouteError extends RuntimeException
{
    public function __construct(string $message, public readonly int $sourceLine = 1, public readonly ?string $sourceFile = null)
    {
        parent::__construct($message);
    }
}
