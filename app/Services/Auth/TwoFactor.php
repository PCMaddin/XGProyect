<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Operator;
use App\Models\User;
use App\Services\Instances\InstanceContext;
use App\Services\SettingsService;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\PlainTextRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Two-factor sign-in of an account (a player's, or an operator's): a secret
 * shared with the authenticator app (stored encrypted), and one-time
 * recovery codes for a lost phone (stored as hashes; shown once).
 */
class TwoFactor
{
    public const RECOVERY_CODES = 8;

    public function __construct(
        private Totp $totp,
        private SettingsService $settings,
        private InstanceContext $context,
    ) {
    }

    public function newSecret(): string
    {
        return $this->totp->secret();
    }

    /**
     * The QR code the authenticator app scans, as SVG.
     */
    public function qrCode(User | Operator $user, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd()));

        return $writer->writeString($this->totp->uri($user instanceof Operator ? $this->operatorIssuer() : $this->issuer(), $user->email, $secret));
    }

    /**
     * The QR code in text, for a terminal; and the secret to type in instead.
     */
    public function qrText(User | Operator $user, string $secret): string
    {
        return (new Writer(new PlainTextRenderer(2)))->writeString($this->totp->uri($user instanceof Operator ? $this->operatorIssuer() : $this->issuer(), $user->email, $secret));
    }

    /**
     * Switches two-factor sign-in on once the app shows the right code.
     *
     * @return list<string>|null the recovery codes, or null for a wrong code
     */
    public function enable(User | Operator $user, string $secret, string $code, int $now): ?array
    {
        $step = $this->totp->verify($secret, $code, $now);

        if ($step === null) {
            return null;
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => self::hashes($codes),
            'two_factor_confirmed_at' => $now,
            'two_factor_last_step' => $step,
        ])->save();

        return $codes;
    }

    /**
     * Whether the code from the app, or an unused recovery code, is right.
     * Each counts once: an app code not again, a recovery code never again.
     */
    public function check(User | Operator $user, string $code, int $now): bool
    {
        if (!$user->hasTwoFactor()) {
            return false;
        }

        $step = $this->totp->verify((string) $user->two_factor_secret, $code, $now, $user->two_factor_last_step);

        if ($step !== null) {
            $user->forceFill(['two_factor_last_step' => $step])->save();

            return true;
        }

        return $this->useRecoveryCode($user, $code);
    }

    public function disable(User | Operator $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    /**
     * New recovery codes; the old ones stop working.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User | Operator $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => self::hashes($codes)])->save();

        return $codes;
    }

    public function recoveryCodesLeft(User | Operator $user): int
    {
        return count($this->storedHashes($user));
    }

    private function useRecoveryCode(User | Operator $user, string $code): bool
    {
        $hash = hash('sha256', self::normalized($code));
        $hashes = $this->storedHashes($user);
        $index = array_search($hash, $hashes, true);

        if ($index === false) {
            return false;
        }

        unset($hashes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => json_encode(array_values($hashes))])->save();

        return true;
    }

    /**
     * @return list<string>
     */
    private function storedHashes(User | Operator $user): array
    {
        $hashes = json_decode((string) $user->two_factor_recovery_codes, true);

        return is_array($hashes) ? array_values(array_filter($hashes, 'is_string')) : [];
    }

    /**
     * @return list<string> like "k3f9a-2xq7m"
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    private function newRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = strtolower(Totp::base32(random_bytes(7)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }

        return $codes;
    }

    /**
     * @param list<string> $codes
     */
    private static function hashes(array $codes): string
    {
        return (string) json_encode(array_map(static fn (string $code): string => hash('sha256', self::normalized($code)), $codes));
    }

    private static function normalized(string $code): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $code));
    }

    /**
     * The name the app lists an operator's account under: the server.
     */
    private function operatorIssuer(): string
    {
        $name = config('app.name');

        return (is_string($name) && $name !== '' ? $name : 'XG Proyect') . ' (operator)';
    }

    /**
     * The name the app lists the account under: the game, and the universe.
     */
    private function issuer(): string
    {
        $game = $this->settings->getString('game_name');
        $universe = $this->context->current()?->name;

        return $universe !== null && $universe !== $game ? $game . ' (' . $universe . ')' : $game;
    }
}
