<?php

declare(strict_types=1);

namespace App\Services\Operators;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The load of xgp:loadtest:run: signed-in players (each with its own
 * session) open game pages in the mix real players do, many at once, as a
 * browser would (no redirects followed: a sign-out shows as 302).
 */
class LoadRunner
{
    /** what players open, by how often: the overview most, then the galaxy */
    public const PAGES = [
        'overview' => 6, 'galaxy' => 3, 'supplies' => 2, 'facilities' => 2, 'fleet1' => 2, 'messages' => 2, 'research' => 1,
        'highscore' => 1, 'movement' => 1, 'shipyard' => 1, 'defenses' => 1, 'empire' => 1, 'alliance' => 1,
    ];
    private const TIMEOUT = 30;

    private Client $client;

    /** @var callable(int): void */
    private $sleep;

    /**
     * @param callable(int): void|null $sleep waits so many seconds (tests: none)
     */
    public function __construct(private string $base, private ?string $universe = null, ?Client $client = null, ?callable $sleep = null)
    {
        $this->base = rtrim($base, '/');
        $this->client = $client ?? new Client(['timeout' => self::TIMEOUT, 'http_errors' => false, 'allow_redirects' => false]);
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Signs the players in, one after the other; waits when the server
     * says too many sign-ins (20 a minute from one address).
     *
     * @param list<string>              $emails
     * @param callable(string): void|null $waiting told how long it waits
     *
     * @return list<CookieJar>
     */
    public function signIn(array $emails, string $password, ?callable $waiting = null): array
    {
        $jars = [];

        foreach ($emails as $email) {
            $jars[] = $this->signInOne($email, $password, $waiting);
        }

        return $jars;
    }

    /**
     * Opens $requests pages, $concurrency at a time, each as a random player.
     *
     * @param list<CookieJar> $jars
     *
     * @return array{requests: int, seconds: float, ok: int, codes: array<int|string, int>, median: float, p95: float, p99: float, slowest: float}
     */
    public function run(array $jars, int $requests, int $concurrency): array
    {
        if ($jars === []) {
            throw new RuntimeException('No player is signed in.');
        }

        $pages = [];

        foreach (self::PAGES as $page => $weight) {
            $pages = array_merge($pages, array_fill(0, $weight, $page));
        }

        $codes = [];
        $times = [];
        $make = function () use ($jars, $pages, $requests, &$times): \Generator {
            for ($i = 0; $i < $requests; $i++) {
                $jar = $jars[array_rand($jars)];
                $page = $pages[array_rand($pages)];

                // a closure, not fn: fn would take a copy of $times
                yield function () use ($jar, $page, &$times): PromiseInterface {
                    return $this->client->getAsync($this->base . '/game.php?page=' . $page, [
                        'cookies' => $jar,
                        'on_stats' => static function (TransferStats $stats) use (&$times): void {
                            $times[] = (float) $stats->getTransferTime();
                        },
                    ]);
                };
            }
        };

        $start = microtime(true);
        (new Pool($this->client, $make(), [
            'concurrency' => max(1, $concurrency),
            'fulfilled' => static function (ResponseInterface $response) use (&$codes): void {
                $code = $response->getStatusCode();
                $codes[$code] = ($codes[$code] ?? 0) + 1;
            },
            'rejected' => static function () use (&$codes): void {
                $codes['failed'] = ($codes['failed'] ?? 0) + 1;
            },
        ]))->promise()->wait();
        $seconds = microtime(true) - $start;

        sort($times);
        ksort($codes);

        return [
            'requests' => $requests, 'seconds' => $seconds, 'ok' => $codes[200] ?? 0, 'codes' => $codes,
            'median' => $this->percentile($times, 0.5), 'p95' => $this->percentile($times, 0.95), 'p99' => $this->percentile($times, 0.99), 'slowest' => $this->percentile($times, 1.0),
        ];
    }

    /**
     * @param list<float> $sorted
     */
    private function percentile(array $sorted, float $share): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        return $sorted[min(count($sorted) - 1, (int) floor(count($sorted) * $share))];
    }

    /**
     * @param callable(string): void|null $waiting
     */
    private function signInOne(string $email, string $password, ?callable $waiting): CookieJar
    {
        for ($try = 0; $try < 10; $try++) {
            $jar = new CookieJar();
            $start = $this->client->get($this->base . '/' . ($this->universe !== null ? '?universe=' . rawurlencode($this->universe) : ''), ['cookies' => $jar]);

            if (preg_match('/name="_token" value="([^"]+)"/', (string) $start->getBody(), $token) !== 1) {
                throw new RuntimeException('The start page (' . $this->base . ') has no sign-in form: HTTP ' . $start->getStatusCode() . '.');
            }

            try {
                $response = $this->client->post($this->base . '/login', [
                    'cookies' => $jar,
                    'form_params' => ['_token' => $token[1], 'username' => $email, 'password' => $password],
                ]);
            } catch (TransferException $e) {
                throw new RuntimeException('Signing in ' . $email . ' failed: ' . $e->getMessage(), 0, $e);
            }

            if ($response->getStatusCode() === 429) {
                $seconds = max(1, (int) $response->getHeaderLine('Retry-After'));

                if ($waiting !== null) {
                    $waiting('Too many sign-ins, waiting ' . $seconds . ' s');
                }

                ($this->sleep)($seconds);

                continue;
            }

            if (!str_contains($response->getHeaderLine('Location'), 'game.php')) {
                throw new RuntimeException($email . ' could not sign in (HTTP ' . $response->getStatusCode() . ' to ' . ($response->getHeaderLine('Location') ?: 'nowhere') . '): wrong password or universe?');
            }

            return $jar;
        }

        throw new RuntimeException($email . ' could not sign in: the server kept saying too many sign-ins.');
    }
}
