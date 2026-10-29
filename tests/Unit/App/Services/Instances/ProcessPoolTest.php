<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Instances;

use App\Services\Instances\ProcessPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The tick runs every universe in a process of its own: side by side, at
 * most so many at a time, waiting for all, and telling which failed.
 */
#[CoversClass(ProcessPool::class)]
class ProcessPoolTest extends TestCase
{
    public function testTheProcessesRunSideBySideUpToTheLimit(): void
    {
        $started = microtime(true);
        $results = (new ProcessPool())->run($this->sleepers(3), 3);
        $sideBySide = microtime(true) - $started;

        $this->assertSame(['a' => [true, 'a'], 'b' => [true, 'b'], 'c' => [true, 'c']], $results);
        $this->assertLessThan(1.3, $sideBySide);

        $started = microtime(true);
        (new ProcessPool())->run($this->sleepers(3), 1);
        $this->assertGreaterThanOrEqual(1.5, microtime(true) - $started);
    }

    public function testAFailureAndATimeoutAreTold(): void
    {
        $slow = new Process([PHP_BINARY, '-r', 'sleep(5);']);
        $slow->setTimeout(0.5);

        $results = (new ProcessPool())->run([
            'fine' => new Process([PHP_BINARY, '-r', 'echo "ok";']),
            'broken' => new Process([PHP_BINARY, '-r', 'fwrite(STDERR, "no database"); exit(3);']),
            'slow' => $slow,
        ], 3);

        $this->assertSame([true, 'ok'], $results['fine']);
        $this->assertSame([false, 'no database'], $results['broken']);
        $this->assertFalse($results['slow'][0]);
    }

    /**
     * @return array<string, Process>
     */
    private function sleepers(int $count): array
    {
        $jobs = [];

        foreach (array_slice(['a', 'b', 'c', 'd'], 0, $count) as $name) {
            $jobs[$name] = new Process([PHP_BINARY, '-r', 'usleep(500000); echo "' . $name . '";']);
        }

        return $jobs;
    }
}
