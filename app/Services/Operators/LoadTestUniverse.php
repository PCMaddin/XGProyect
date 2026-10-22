<?php

declare(strict_types=1);

namespace App\Services\Operators;

use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use RuntimeException;

/**
 * Where the load-test commands work: the universe named by --universe when
 * there are several, else the one game.
 */
class LoadTestUniverse
{
    public function __construct(private InstanceRegistry $registry, private InstanceContext $context)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(mixed $slug, callable $work): mixed
    {
        if (!$this->registry->enabled()) {
            return $work();
        }

        if (!is_string($slug) || $slug === '') {
            throw new RuntimeException('Name the universe of the test: --universe=<slug> (xgp:instance:list).');
        }

        $instance = $this->registry->find($slug);

        if ($instance === null) {
            throw new RuntimeException('There is no universe ' . $slug . '.');
        }

        return $this->context->runIn($instance, $work);
    }
}
