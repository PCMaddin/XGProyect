<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Premium;

/**
 * Actions as a Hyperion history server sends them (v2/history/get_actions).
 */
trait WaxFixtures
{
    /** 2030-01-01 12:00:00 UTC, the time of the fixtures' transfers */
    private const NOON = 1893499200;

    /**
     * @param array<string, mixed> $changes act.account, quantity, from, to, memo, block_num, global_sequence, timestamp
     *
     * @return array<string, mixed>
     */
    private static function action(array $changes = []): array
    {
        $value = static fn (string $key, mixed $default): mixed => array_key_exists($key, $changes) ? $changes[$key] : $default;

        return [
            '@timestamp' => $value('timestamp', '2030-01-01T12:00:00.000'),
            'timestamp' => $value('timestamp', '2030-01-01T12:00:00.000'),
            'block_num' => $value('block_num', 1000),
            'trx_id' => $value('trx_id', str_repeat('a', 64)),
            'global_sequence' => $value('global_sequence', 5000),
            'act' => [
                'account' => $value('contract', 'eosio.token'),
                'name' => $value('name', 'transfer'),
                'authorization' => [['actor' => 'player.wam', 'permission' => 'active']],
                'data' => [
                    'from' => $value('from', 'player.wam'),
                    'to' => $value('to', 'xgpshop'),
                    'amount' => 1.0,
                    'symbol' => 'WAX',
                    'memo' => $value('memo', 'xgp:1'),
                    'quantity' => $value('quantity', '467.25000000 WAX'),
                ],
            ],
        ];
    }
}
