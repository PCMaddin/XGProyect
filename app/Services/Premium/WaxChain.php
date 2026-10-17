<?php

declare(strict_types=1);

namespace App\Services\Premium;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reads the WAX chain through a Hyperion history server: the last block
 * that can no longer be undone, and the transfers of WAX to an account.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class WaxChain
{
    /** the system token; anything else called "WAX" is worth nothing */
    public const CONTRACT = 'eosio.token';
    public const SYMBOL = 'WAX';
    public const UNITS = 100_000_000;

    public const PAGE_SIZE = 100;

    /**
     * @throws WaxException
     */
    public function lastIrreversibleBlock(): int
    {
        $info = $this->get('/v1/chain/get_info', []);
        $block = $info['last_irreversible_block_num'] ?? null;

        if (!is_int($block) || $block <= 0) {
            throw new WaxException('The chain did not name its last irreversible block');
        }

        return $block;
    }

    /**
     * A page of the account's WAX transfers (to it and from it) after the
     * given time, oldest first, and how many actions the page held.
     *
     * @return array{transfers: list<WaxTransfer>, actions: int}
     *
     * @throws WaxException
     */
    public function transfers(string $account, int $after, int $skip): array
    {
        $history = $this->get('/v2/history/get_actions', [
            'account' => $account,
            'filter' => self::CONTRACT . ':transfer',
            'sort' => 'asc',
            'after' => gmdate('Y-m-d\TH:i:s', $after),
            'limit' => self::PAGE_SIZE,
            'skip' => $skip,
        ]);
        $actions = $history['actions'] ?? null;

        if (!is_array($actions)) {
            throw new WaxException('The history server sent no actions');
        }

        $transfers = [];

        foreach ($actions as $action) {
            $transfer = is_array($action) ? self::transfer($action) : null;

            if ($transfer !== null) {
                $transfers[] = $transfer;
            }
        }

        return ['transfers' => $transfers, 'actions' => count($actions)];
    }

    /**
     * A transfer of the system token, or null for anything else.
     *
     * @param array<mixed> $action
     */
    public static function transfer(array $action): ?WaxTransfer
    {
        $act = is_array($action['act'] ?? null) ? $action['act'] : [];
        $data = is_array($act['data'] ?? null) ? $act['data'] : [];

        if (($act['account'] ?? null) !== self::CONTRACT || ($act['name'] ?? null) !== 'transfer') {
            return null;
        }

        $transfer = new WaxTransfer(
            self::int($action, 'global_sequence'),
            self::text($action, 'trx_id'),
            self::int($action, 'block_num'),
            (int) strtotime(self::text($action, 'timestamp') . 'Z'),
            self::text($data, 'from'),
            self::text($data, 'to'),
            self::units(self::text($data, 'quantity')) ?? 0,
            self::text($data, 'memo'),
        );

        return self::complete($transfer) ? $transfer : null;
    }

    private static function complete(WaxTransfer $transfer): bool
    {
        return $transfer->sequence > 0 && $transfer->blockNum > 0 && $transfer->blockTime > 0 && $transfer->units > 0 &&
            $transfer->trxId !== '' && $transfer->from !== '' && $transfer->to !== '';
    }

    /**
     * @param array<mixed> $values
     */
    private static function int(array $values, string $key): int
    {
        return is_int($values[$key] ?? null) ? $values[$key] : 0;
    }

    /**
     * @param array<mixed> $values
     */
    private static function text(array $values, string $key): string
    {
        return is_string($values[$key] ?? null) ? $values[$key] : '';
    }

    /**
     * "12.50000000 WAX" in units, or null if it is not WAX with its 8 decimals.
     */
    public static function units(string $quantity): ?int
    {
        if (preg_match('/^(\d{1,11})\.(\d{8}) ' . self::SYMBOL . '$/', $quantity, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * self::UNITS + (int) $match[2];
    }

    /**
     * @param array<string, int|string> $query
     *
     * @return array<mixed>
     *
     * @throws WaxException
     */
    private function get(string $path, array $query): array
    {
        try {
            $response = Http::acceptJson()->timeout(15)->get(self::apiUrl() . $path, $query);
        } catch (HttpClientException | TransferException $exception) {
            throw new WaxException('The WAX history server could not be reached: ' . $exception->getMessage(), 0, $exception);
        }

        return self::json($response);
    }

    /**
     * @return array<mixed>
     *
     * @throws WaxException
     */
    private static function json(Response $response): array
    {
        $json = $response->json();

        if (!$response->successful() || !is_array($json)) {
            throw new WaxException('The WAX history server answered HTTP ' . $response->status());
        }

        return $json;
    }

    private static function apiUrl(): string
    {
        $url = config('premium.wax.api_url');

        return rtrim(is_string($url) && $url !== '' ? $url : 'https://wax.eosusa.io', '/');
    }
}
