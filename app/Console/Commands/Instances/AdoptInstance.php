<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\Instance;
use App\Services\Instances\InstanceProvisioner;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * The game installed so far becomes the first universe of the lobby.
 */
class AdoptInstance extends Command
{
    /** @var string */
    protected $signature = 'xgp:instance:adopt
        {slug : short name in the lobby (a-z, 0-9, -)}
        {--name= : name shown in the lobby, default: the game name}
        {--mode= : pay2win or play2win, default: the premium_mode option}';

    /** @var string */
    protected $description = 'Register the installed game as a universe';

    public function handle(InstanceProvisioner $provisioner, SettingsService $settings): int
    {
        $database = config('database.connections.' . self::str(config('database.default')) . '.database');
        $instance = new Instance(
            self::str($this->argument('slug')),
            self::str($this->option('name')) ?: self::str(($settings->all()['game_name'] ?? null)?->value),
            self::str($database),
            self::str($this->option('mode')) ?: (self::str(($settings->all()['premium_mode'] ?? null)?->value) ?: Instance::PLAY2WIN),
            Instance::OPEN,
            time(),
        );

        try {
            $provisioner->adopt($instance);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('The game in ' . $instance->database . ' is the universe ' . $instance->slug . ' (' . $instance->mode . ').');

        return self::SUCCESS;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
