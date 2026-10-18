<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\Instance;
use App\Services\Instances\InstanceProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * A new universe in a database of its own, with an administrator; players
 * find it in the lobby at once.
 */
class CreateInstance extends Command
{
    /** @var string */
    protected $signature = 'xgp:instance:create
        {slug : short name, in the lobby cookie and the database name (a-z, 0-9, -)}
        {--name= : name shown in the lobby, default: the slug}
        {--mode=play2win : pay2win (dark matter for money) or play2win (no purchase)}
        {--payments=stripe : ways to pay in pay2win: stripe, wax or stripe,wax}
        {--bots=0 : AI opponents to start with}
        {--database= : database of the universe, default: xgp_uni_<slug>}
        {--admin-name= : administrator of the universe}
        {--admin-email=}
        {--admin-password=}
        {--option=* : further options of the universe, name=value (game_speed=5000)}';

    /** @var string */
    protected $description = 'Create a universe in a database of its own';

    public function handle(InstanceProvisioner $provisioner): int
    {
        $slug = self::str($this->argument('slug'));
        $instance = new Instance(
            $slug,
            $this->text('name') ?: $slug,
            $this->text('database') ?: $provisioner->databaseFor($slug),
            $this->text('mode'),
            Instance::OPEN,
            time(),
        );

        try {
            $provisioner->validate($instance);
            $options = ['payment_methods' => $provisioner->paymentMethods($this->text('payments'))] + $this->universeOptions();
            $provisioner->create(
                $instance,
                $this->text('admin-name') ?: self::str($this->ask('Administrator name')),
                $this->text('admin-email') ?: self::str($this->ask('Administrator e-mail')),
                $this->text('admin-password') ?: self::str($this->secret('Administrator password')),
                $options,
                max(0, (int) $this->text('bots')),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Universe ' . $instance->slug . ' (' . $instance->mode . ') is open in the database ' . $instance->database . '.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function universeOptions(): array
    {
        $options = [];
        foreach ($this->option('option') as $pair) {
            [$name, $value] = array_pad(explode('=', $pair ?? '', 2), 2, '');

            if ($name !== '') {
                $options[$name] = $value;
            }
        }

        return $options;
    }

    private function text(string $option): string
    {
        return self::str($this->option($option));
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
