<?php

declare(strict_types=1);

namespace App\Console\Commands\Operators;

use App\Services\Operators\LoadRunner;
use App\Services\Operators\LoadTestPlayers;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Puts load on a running server with the players of xgp:loadtest:seed and
 * says how many requests a second it answers, and how fast.
 */
class RunLoadTest extends Command
{
    /** a playing player opens about one page every ten seconds */
    private const SECONDS_PER_PAGE = 10;

    /** @var string */
    protected $signature = 'xgp:loadtest:run
        {url : the game, e.g. https://play.example.com}
        {--universe= : the universe of the test players (with several universes)}
        {--password= : the password given to xgp:loadtest:seed}
        {--players=20 : how many of them sign in (20 sign-ins a minute)}
        {--requests=2000 : pages to open}
        {--concurrency=20 : pages open at the same time}';

    /** @var string */
    protected $description = 'Measure how many page views a second the server answers';

    public function handle(): int
    {
        $url = $this->argument('url');
        $password = $this->option('password');
        $universe = $this->option('universe');

        if (!is_string($password) || $password === '') {
            $this->error('Give the URL and the players\' password: xgp:loadtest:run https://… --password=…');

            return self::FAILURE;
        }

        $runner = new LoadRunner($url, is_string($universe) && $universe !== '' ? $universe : null);
        $emails = array_map(static fn (int $number): string => 'load' . $number . '@' . LoadTestPlayers::DOMAIN, range(1, max(1, (int) $this->option('players'))));

        try {
            $this->info('Signing in ' . count($emails) . ' players …');
            $jars = $runner->signIn($emails, $password, fn (string $note) => $this->line($note));
            $this->info('Opening ' . (int) $this->option('requests') . ' pages, ' . (int) $this->option('concurrency') . ' at a time …');
            $result = $runner->run($jars, max(1, (int) $this->option('requests')), (int) $this->option('concurrency'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->report($result);

        return $result['ok'] === $result['requests'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array{requests: int, seconds: float, ok: int, codes: array<int|string, int>, median: float, p95: float, p99: float, slowest: float} $result
     */
    private function report(array $result): void
    {
        $perSecond = $result['requests'] / max(0.001, $result['seconds']);
        $ms = static fn (float $seconds): string => number_format($seconds * 1000) . ' ms';
        $codes = [];

        foreach ($result['codes'] as $code => $count) {
            $codes[] = $code . ': ' . $count;
        }

        $this->table(['', ''], [
            ['Pages', $result['requests'] . ' in ' . number_format($result['seconds'], 1) . ' s'],
            ['Per second', number_format($perSecond, 1)],
            ['Answers', implode(', ', $codes)],
            ['Median', $ms($result['median'])],
            ['95 %', $ms($result['p95'])],
            ['99 %', $ms($result['p99'])],
            ['Slowest', $ms($result['slowest'])],
        ]);

        $this->line('About ' . number_format($perSecond * self::SECONDS_PER_PAGE) . ' players playing at once (one page every '
            . self::SECONDS_PER_PAGE . ' s each), if the times stay acceptable at this concurrency.');

        if ($result['ok'] !== $result['requests']) {
            $this->warn('Not every page answered 200: 302 means signed out, 5xx an error (storage/logs, /operator → Errors), "failed" a timeout or a dropped connection.');
        }
    }
}
