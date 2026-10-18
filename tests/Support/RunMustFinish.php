<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Event\TestRunner\Finished;
use PHPUnit\Event\TestRunner\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Code under test that calls exit ends PHPUnit on the spot, with status 0:
 * the rest of the run is skipped and CI shows it as passed. This fails such
 * a run and names the test it ended in.
 */
final class RunMustFinish implements Extension
{
    private static bool $finished = false;

    private static string $current = '';

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(
            new class () implements PreparedSubscriber {
                public function notify(Prepared $event): void
                {
                    RunMustFinish::started($event->test()->id());
                }
            },
            new class () implements FinishedSubscriber {
                public function notify(Finished $event): void
                {
                    RunMustFinish::finished();
                }
            },
        );
    }

    public static function started(string $test): void
    {
        // armed with the first test: listing the tests runs none
        if (self::$current === '') {
            register_shutdown_function(static function (): void {
                if (!self::$finished) {
                    fwrite(STDERR, PHP_EOL . 'The test run ended early (exit in the code under test?) in ' . self::$current . PHP_EOL);
                    exit(1);
                }
            });
        }

        self::$current = $test;
    }

    public static function finished(): void
    {
        self::$finished = true;
    }
}
