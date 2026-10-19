<?php

declare(strict_types=1);

namespace App\Services\Account;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The invitation codes of this universe. A code is good for a number of
 * registrations and maybe until a date; taking one is a single update, so
 * two players cannot both take its last use.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Invitations
{
    /** without 0/O, 1/I/L: read aloud and typed without mix-ups */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 10;

    public function create(int $uses, ?int $expiresAt, string $note, string $by, int $now): string
    {
        do {
            $code = '';

            for ($i = 0; $i < self::LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while ($this->table()->where('code', $code)->exists());

        $this->table()->insert([
            'code' => $code,
            'uses' => max(1, $uses),
            'expires_at' => $expiresAt,
            'note' => mb_substr($note, 0, 100),
            'created_by' => mb_substr($by, 0, 64),
            'created_at' => $now,
        ]);

        return $code;
    }

    /**
     * Takes one use of the code, if it has one left and has not expired.
     */
    public function redeem(string $code, int $now): bool
    {
        return $this->table()
            ->where('code', self::normalize($code))
            ->whereColumn('used', '<', 'uses')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->increment('used') === 1;
    }

    public function giveBack(string $code): void
    {
        $this->table()->where('code', self::normalize($code))->where('used', '>', 0)->decrement('used');
    }

    /**
     * @return list<array{id: int, code: string, uses: int, used: int, expires_at: int|null, note: string, created_by: string, created_at: int}>
     */
    public function all(): array
    {
        return array_values($this->table()->orderByDesc('id')->get()->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'uses' => (int) $row->uses,
            'used' => (int) $row->used,
            'expires_at' => $row->expires_at === null ? null : (int) $row->expires_at,
            'note' => (string) $row->note,
            'created_by' => (string) $row->created_by,
            'created_at' => (int) $row->created_at,
        ])->all());
    }

    public function revoke(int $id): void
    {
        $this->table()->where('id', $id)->delete();
    }

    /**
     * As typed or pasted: case, spaces and dashes do not matter.
     */
    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    private function table(): Builder
    {
        return DB::table('invitations');
    }
}
