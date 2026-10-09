<?php

declare(strict_types=1);

namespace Unphramed\Tools;

final class Process
{
    public static function run(array $command): string
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) {
            throw new RouteError('Could not start validation command');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            throw new RouteError(trim($output));
        }
        return $output;
    }
}
