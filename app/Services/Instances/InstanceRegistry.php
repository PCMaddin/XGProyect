<?php

declare(strict_types=1);

namespace App\Services\Instances;

use App\Support\Concerns\ReadsMixedValues;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The universes of the server, in the table `instances` of the base
 * database (connection `central`, the database of config/xgp-db-config.php).
 * Without the table or without a registered universe the server is a single
 * universe, as before.
 */
class InstanceRegistry
{
    use ReadsMixedValues;

    public const CONNECTION = 'central';

    private ?bool $enabled = null;

    /**
     * Whether universes are registered: then players choose one in the lobby.
     */
    public function enabled(): bool
    {
        if ($this->enabled !== null) {
            return $this->enabled;
        }

        try {
            return $this->enabled = Schema::connection(self::CONNECTION)->hasTable('instances') && $this->table()->exists();
        } catch (Throwable) {
            // not installed yet, or no database server: a single universe
            return $this->enabled = false;
        }
    }

    /**
     * @return list<Instance>
     */
    public function all(): array
    {
        return $this->enabled()
            ? array_values($this->table()->orderByDesc('opened_at')->orderBy('slug')->get()->map(fn (object $row): Instance => $this->instance($row))->all())
            : [];
    }

    /**
     * @return list<Instance>
     */
    public function open(): array
    {
        return array_values(array_filter($this->all(), static fn (Instance $instance): bool => $instance->isOpen()));
    }

    public function find(string $slug): ?Instance
    {
        if (!$this->enabled()) {
            return null;
        }

        $row = $this->table()->where('slug', $slug)->first();

        return $row === null ? null : $this->instance($row);
    }

    public function register(Instance $instance): void
    {
        $this->table()->insert([
            'slug' => $instance->slug,
            'name' => $instance->name,
            'database' => $instance->database,
            'mode' => $instance->mode,
            'status' => $instance->status,
            'opened_at' => $instance->openedAt,
        ]);
        $this->enabled = true;
    }

    /**
     * @param array<string, string|int> $changes name, mode, status
     */
    public function update(string $slug, array $changes): void
    {
        $this->table()->where('slug', $slug)->update($changes);
    }

    private function table(): Builder
    {
        return DB::connection(self::CONNECTION)->table('instances');
    }

    private function instance(object $row): Instance
    {
        $values = get_object_vars($row);

        return new Instance(
            self::asString($values['slug'] ?? ''),
            self::asString($values['name'] ?? ''),
            self::asString($values['database'] ?? ''),
            self::asString($values['mode'] ?? Instance::PLAY2WIN),
            self::asString($values['status'] ?? Instance::OPEN),
            self::asInt($values['opened_at'] ?? 0),
        );
    }
}
