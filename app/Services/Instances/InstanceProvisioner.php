<?php

declare(strict_types=1);

namespace App\Services\Instances;

use App\Services\Admin\BotService;
use App\Services\Premium\DarkMatterShop;
use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Sets up universes: the registry table, a database of their own with the
 * game's tables, options and an administrator, and the update migrations of
 * every universe.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class InstanceProvisioner
{
    /** slugs end up in cookies, cache prefixes and database names */
    public const SLUG = '/^[a-z0-9][a-z0-9-]{0,31}$/';

    private const DATABASE = '/^[A-Za-z0-9_]{1,64}$/';

    /** what the installer runs, without wiping anything */
    private const INSTALL = [
        ['migrate:install', []],
        ['migrate', ['--path' => 'database/migrations/install', '--force' => true]],
        ['xgp:mark-installed-updates', ['--all' => true]],
        ['db:seed', ['--class' => 'ChangelogTableSeeder', '--force' => true]],
        ['db:seed', ['--class' => 'LanguagesTableSeeder', '--force' => true]],
        ['db:seed', ['--class' => 'OptionsTableSeeder', '--force' => true]],
    ];

    /** options a new universe takes from the base game */
    private const INHERITED = ['lang', 'date_time_zone'];

    public function __construct(
        private Artisan $artisan,
        private InstanceContext $context,
        private InstanceRegistry $registry,
        private AdminAccount $admins,
        private SeasonResults $results,
    ) {
    }

    /**
     * The table of the universes in the base database.
     */
    public function prepareRegistry(): void
    {
        $this->run('migrate', ['--database' => InstanceRegistry::CONNECTION, '--path' => 'database/migrations/central', '--force' => true]);
        $this->registry->refresh();
    }

    /**
     * The database a new universe gets when none is named: xgp_uni_<slug>.
     */
    public function databaseFor(string $slug): string
    {
        return 'xgp_uni_' . str_replace('-', '_', $slug);
    }

    /**
     * A new universe in a database of its own: created if missing, which
     * must hold no game yet; registered once it is installed.
     *
     * @param array<string, string> $options more options of the universe, by name
     * @param int                   $bots    AI opponents spread over the galaxies
     */
    public function create(Instance $instance, string $adminName, string $adminEmail, string $adminPassword, array $options = [], int $bots = 0): void
    {
        $this->validate($instance);
        $this->prepareRegistry();

        if ($this->registry->find($instance->slug) !== null) {
            throw new InvalidArgumentException('The universe ' . $instance->slug . ' exists already.');
        }

        foreach ($this->registry->all() as $other) {
            if ($other->database === $instance->database) {
                throw new InvalidArgumentException('The database ' . $instance->database . ' belongs to the universe ' . $other->slug . '.');
            }
        }

        $this->createDatabase($instance->database);
        $options += $this->inheritedOptions();

        $this->context->runIn($instance, function () use ($instance, $adminName, $adminEmail, $adminPassword, $options, $bots): void {
            if (DB::connection()->getSchemaBuilder()->hasTable('migrations')) {
                throw new RuntimeException('The database ' . $instance->database . ' holds a game already.');
            }

            foreach (self::INSTALL as [$command, $parameters]) {
                $this->run($command, $parameters);
            }

            // the options were read before the seeders wrote them
            $this->context->activate($instance);

            $this->admins->create($adminName, $adminEmail, $adminPassword);
            $this->writeOptions(['game_name' => $instance->name, 'premium_mode' => $instance->mode] + $options);

            if ($bots > 0) {
                // resolved here: it reads the options of the new universe
                app(BotService::class)->createBots($bots, 1, MAX_GALAXY_IN_WORLD);
            }
        });

        $this->registry->register($instance);
    }

    /**
     * The base database becomes a universe of the lobby, as it is.
     */
    public function adopt(Instance $instance): void
    {
        $this->validate($instance);
        $this->prepareRegistry();

        if ($this->registry->find($instance->slug) !== null) {
            throw new InvalidArgumentException('The universe ' . $instance->slug . ' exists already.');
        }

        $this->context->runIn($instance, fn () => $this->writeOptions(['premium_mode' => $instance->mode]));
        $this->registry->register($instance);
    }

    /**
     * Changes name, mode or status; the mode is an option of the universe too.
     *
     * @param array{name?: string, mode?: string, status?: string, payments?: string, ends_at?: int|null} $changes
     */
    public function change(Instance $instance, array $changes): Instance
    {
        $payments = isset($changes['payments']) ? $this->paymentMethods($changes['payments']) : null;

        $changed = new Instance(
            $instance->slug,
            $changes['name'] ?? $instance->name,
            $instance->database,
            $changes['mode'] ?? $instance->mode,
            $changes['status'] ?? $instance->status,
            $instance->openedAt,
            array_key_exists('ends_at', $changes) ? $changes['ends_at'] : $instance->endsAt,
        );
        $this->validate($changed);

        // the season is over: keep its rankings before nobody gets in any more
        if ($instance->status === Instance::OPEN && $changed->status === Instance::CLOSED) {
            $this->results->freeze($instance, time());
        }

        $options = array_filter(['premium_mode' => $changed->mode !== $instance->mode ? $changed->mode : null, 'payment_methods' => $payments], static fn (?string $value): bool => $value !== null);

        if ($options !== []) {
            $this->context->runIn($changed, fn () => $this->writeOptions($options));
        }

        $this->registry->update($instance->slug, ['name' => $changed->name, 'mode' => $changed->mode, 'status' => $changed->status, 'ends_at' => $changed->endsAt]);

        return $changed;
    }

    /**
     * The season is over: nobody plays in the universe any more.
     */
    public function close(Instance $instance): Instance
    {
        return $this->change($instance, ['status' => Instance::CLOSED]);
    }

    /**
     * The update migrations of a universe; returns what they printed.
     */
    public function migrate(Instance $instance): string
    {
        return $this->context->runIn($instance, fn (): string => $this->run('migrate', ['--path' => 'database/migrations/400', '--force' => true]));
    }

    /**
     * The ways to pay of a universe, checked: "stripe", "wax", "stripe,wax"
     * or "" (none).
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function paymentMethods(string $list): string
    {
        $named = array_filter(array_map('trim', explode(',', strtolower($list))), static fn (string $method): bool => $method !== '');
        $known = DarkMatterShop::parseMethods($list);

        if (count($known) !== count(array_unique($named))) {
            throw new InvalidArgumentException('The ways to pay are ' . implode(', ', DarkMatterShop::METHODS) . '.');
        }

        return implode(',', $known);
    }

    public function validate(Instance $instance): void
    {
        $errors = array_filter([
            preg_match(self::SLUG, $instance->slug) === 1 ? null : 'The slug may hold lower-case letters, digits and dashes (up to 32).',
            preg_match(self::DATABASE, $instance->database) === 1 ? null : 'The database name may hold letters, digits and underscores (up to 64).',
            in_array($instance->mode, [Instance::PAY2WIN, Instance::PLAY2WIN], true) ? null : 'The mode is pay2win or play2win.',
            in_array($instance->status, [Instance::OPEN, Instance::CLOSED], true) ? null : 'The status is open or closed.',
            trim($instance->name) !== '' && mb_strlen($instance->name) <= 64 ? null : 'The name holds 1 to 64 characters.',
            $instance->endsAt === null || $instance->endsAt > $instance->openedAt ? null : 'The season must end after it starts.',
        ]);

        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
    }

    /**
     * Language and time zone of the base game, for universes that name none.
     *
     * @return array<string, string>
     */
    private function inheritedOptions(): array
    {
        try {
            $values = DB::connection(InstanceRegistry::CONNECTION)->table('options')->whereIn('name', self::INHERITED)->pluck('value', 'name')->all();
        } catch (Throwable) {
            // the base database holds no game
            return [];
        }

        return array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    private function createDatabase(string $database): void
    {
        $connection = DB::connection(InstanceRegistry::CONNECTION);

        if (!in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Universes need a MySQL or MariaDB server.');
        }

        // the name is checked against self::DATABASE: nothing to escape
        $connection->statement('CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    /**
     * @param array<string, string> $options
     */
    private function writeOptions(array $options): void
    {
        foreach ($options as $name => $value) {
            $exists = DB::table('options')->where('name', $name)->exists();

            $exists
                ? DB::table('options')->where('name', $name)->update(['value' => $value])
                : DB::table('options')->insert(['name' => $name, 'value' => $value, 'type' => is_numeric($value) ? 'int' : 'string']);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function run(string $command, array $parameters): string
    {
        $exitCode = $this->artisan->call($command, $parameters);
        $output = $this->artisan->output();

        if ($exitCode !== 0) {
            throw new RuntimeException($command . ' failed: ' . $output);
        }

        return $output;
    }
}
