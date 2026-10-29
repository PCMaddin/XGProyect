<?php

declare(strict_types=1);

namespace App\Services\Instances;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs processes side by side, at most so many at a time, and waits for
 * all of them: the tick of every universe in a process of its own, so a
 * universe busy with its statistics holds up no other.
 */
class ProcessPool
{
    /** how often the pool looks at its processes */
    private const POLL_MICROSECONDS = 50_000;

    /**
     * @param array<string, Process> $jobs     by a name of the caller's
     * @param int                    $parallel the most at a time
     *
     * @return array<string, array{0: bool, 1: string}> by name: success, what it printed
     */
    public function run(array $jobs, int $parallel): array
    {
        $parallel = max(1, $parallel);
        $pending = $jobs;
        $running = [];
        $results = [];

        while ($pending !== [] || $running !== []) {
            $free = $parallel - count($running);

            for (; $free > 0 && $pending !== []; $free--) {
                $name = (string) array_key_first($pending);
                $running[$name] = $pending[$name];
                unset($pending[$name]);
                $running[$name]->start();
            }

            foreach ($running as $name => $process) {
                if ($this->stillRunning($process)) {
                    continue;
                }

                $results[$name] = [$process->isSuccessful(), self::output($process)];
                unset($running[$name]);
            }

            if ($running !== []) {
                usleep(self::POLL_MICROSECONDS);
            }
        }

        return $results;
    }

    private function stillRunning(Process $process): bool
    {
        if (!$process->isRunning()) {
            return false;
        }

        try {
            $process->checkTimeout();
        } catch (ProcessTimedOutException) {
            // stopped: counts as failed
            return false;
        }

        return true;
    }

    private static function output(Process $process): string
    {
        $output = trim($process->getOutput());

        if ($process->isSuccessful()) {
            return $output;
        }

        $error = trim($process->getErrorOutput());

        return trim($output . "\n" . ($error !== '' ? $error : 'exit code ' . (string) $process->getExitCode()));
    }
}
