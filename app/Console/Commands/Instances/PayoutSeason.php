<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\SeasonPayouts;
use App\Services\Premium\DarkMatterShop;
use App\Services\Premium\TokenSpec;
use Illuminate\Console\Command;

/**
 * Lists the transfers that pay a season's prizes in the game's token. The
 * server holds no key: the operator signs the printed actions in their
 * wallet (or cleos); xgp:wax:check then marks each prize paid by its memo.
 * Until then the prizes stay reserved and cannot be redeemed as dark
 * matter; --release gives them back.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class PayoutSeason extends Command
{
    /** @var string */
    protected $signature = 'xgp:season:payout
        {universe : slug of a closed universe}
        {--release : give the reserved prizes back instead (redeemable as dark matter again)}';

    /** @var string */
    protected $description = 'List the token transfers that pay a season\'s prizes';

    public function handle(InstanceRegistry $registry, SeasonPayouts $payouts, DarkMatterShop $shop): int
    {
        $slug = $this->argument('universe');
        $instance = $registry->find($slug);

        if ($instance === null) {
            $this->error('No universe ' . $slug . '.');

            return self::FAILURE;
        }

        if ($this->option('release')) {
            $this->info('Released ' . $payouts->release($instance->slug) . ' prizes.');

            return self::SUCCESS;
        }

        $token = TokenSpec::game();
        $from = $shop->waxAccount();

        if ($token === null || $from === '') {
            $this->error('Set XGP_WAX_ACCOUNT, XGP_TOKEN_CONTRACT and XGP_TOKEN_SYMBOL in .env first.');

            return self::FAILURE;
        }

        if ($instance->isOpen()) {
            $this->error('The season of ' . $instance->slug . ' is still running.');

            return self::FAILURE;
        }

        $list = $payouts->reserve($instance->slug, $token, time());

        if ($list === []) {
            $this->info('No prizes of linked WAX accounts to pay.');

            return self::SUCCESS;
        }

        $this->table(['Prize', 'Ranking', 'Rank', 'Dark matter', 'To', 'Quantity', 'Memo'], array_map(
            static fn (array $payout): array => [$payout['id'], $payout['category'], $payout['rank'], $payout['reward'], $payout['to'], $payout['quantity'], $payout['memo']],
            $list
        ));
        $this->line('Sign these actions with ' . $from . '@active (e.g. cleos push actions, or a multi-action transaction in your wallet):');
        $this->line((string) json_encode(array_map(static fn (array $payout): array => [
            'account' => $token->contract,
            'name' => 'transfer',
            'authorization' => [['actor' => $from, 'permission' => 'active']],
            'data' => ['from' => $from, 'to' => $payout['to'], 'quantity' => $payout['quantity'], 'memo' => $payout['memo']],
        ], $list), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
