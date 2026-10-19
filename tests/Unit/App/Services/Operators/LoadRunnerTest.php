<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Operators;

use App\Services\Operators\LoadRunner;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use ArrayObject;

/**
 * Signs players in like a browser (token from the start page, the
 * universe in the query), waits out the sign-in limit, and counts what
 * the pages answer.
 */
#[CoversClass(LoadRunner::class)]
class LoadRunnerTest extends TestCase
{
    private const FORM = '<form id="loginForm"><input type="hidden" name="_token" value="tok123"></form>';

    /** @var ArrayObject<array-key, mixed> what the client sent (Guzzle's history) */
    private ArrayObject $sent;

    /** @var list<int> */
    private array $slept = [];

    public function testSignsInWithTheTokenAndWaitsOutTheLimit(): void
    {
        $runner = $this->runner([
            new Response(200, [], self::FORM),
            new Response(302, ['Location' => 'https://play.example.com/game.php?page=overview']),
            new Response(200, [], self::FORM),
            new Response(429, ['Retry-After' => '7']),
            new Response(200, [], self::FORM),
            new Response(302, ['Location' => 'https://play.example.com/game.php?page=overview']),
        ]);
        $notes = [];

        $jars = $runner->signIn(['load1@loadtest.invalid', 'load2@loadtest.invalid'], 'secret-password', function (string $note) use (&$notes): void {
            $notes[] = $note;
        });

        $this->assertCount(2, $jars);
        $this->assertSame([7], $this->slept);
        $this->assertSame(['Too many sign-ins, waiting 7 s'], $notes);
        $this->assertSame('https://play.example.com/?universe=nov', (string) $this->request(0)->getUri());
        $this->assertSame('POST', $this->request(1)->getMethod());
        parse_str((string) $this->request(1)->getBody(), $form);
        $this->assertSame(['_token' => 'tok123', 'username' => 'load1@loadtest.invalid', 'password' => 'secret-password'], $form);
    }

    public function testAWrongPasswordStopsTheTest(): void
    {
        $runner = $this->runner([new Response(200, [], self::FORM), new Response(302, ['Location' => 'https://play.example.com/'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('load1@loadtest.invalid could not sign in');

        $runner->signIn(['load1@loadtest.invalid'], 'wrong', null);
    }

    public function testAStartPageWithoutFormStopsTheTest(): void
    {
        $runner = $this->runner([new Response(502, [], 'Bad gateway')]);

        $this->expectExceptionMessage('has no sign-in form: HTTP 502');

        $runner->signIn(['load1@loadtest.invalid'], 'secret-password', null);
    }

    public function testCountsTheAnswersOfTheGamePages(): void
    {
        $runner = $this->runner([
            new Response(200), new Response(200), new Response(302, ['Location' => '/']), new Response(500),
            new ConnectException('timeout', new Request('GET', 'https://play.example.com/game.php')),
        ]);

        $result = $runner->run([new CookieJar()], 5, 2);

        $this->assertSame(5, $result['requests']);
        $this->assertSame([200 => 2, 302 => 1, 500 => 1, 'failed' => 1], $result['codes']);
        $this->assertSame(2, $result['ok']);
        foreach (array_keys($this->sent->getArrayCopy()) as $index) {
            $this->assertMatchesRegularExpression('#^https://play\.example\.com/game\.php\?page=(' . implode('|', array_keys(LoadRunner::PAGES)) . ')$#', (string) $this->request($index)->getUri());
        }
    }

    private function request(int $index): RequestInterface
    {
        $entry = $this->sent[$index] ?? null;
        $request = is_array($entry) ? ($entry['request'] ?? null) : null;
        $this->assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }

    /**
     * @param list<Response|ConnectException> $answers
     */
    private function runner(array $answers): LoadRunner
    {
        $stack = HandlerStack::create(new MockHandler($answers));
        $history = new ArrayObject();
        $stack->push(Middleware::history($history));
        $this->assertInstanceOf(ArrayObject::class, $history);
        $this->sent = $history;
        $client = new Client(['handler' => $stack, 'http_errors' => false, 'allow_redirects' => false]);

        return new LoadRunner('https://play.example.com/', 'nov', $client, function (int $seconds): void {
            $this->slept[] = $seconds;
        });
    }
}
