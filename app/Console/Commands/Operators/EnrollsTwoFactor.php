<?php

declare(strict_types=1);

namespace App\Console\Commands\Operators;

use App\Models\Operator;
use App\Services\Auth\TwoFactor;

/**
 * Two-factor sign-in of an operator is set up here, on the server's command
 * line, never in the browser: a password alone cannot bring an app of its own.
 */
trait EnrollsTwoFactor
{
    private const CODE_TRIES = 3;

    /**
     * Shows the QR code and asks for a code from the app; saves the operator
     * with two-factor sign-in on and prints the recovery codes.
     */
    private function enrollTwoFactor(TwoFactor $twoFactor, Operator $operator): bool
    {
        $secret = $twoFactor->newSecret();
        $this->line('Scan this with your authenticator app (or type in the key ' . trim(chunk_split($secret, 4, ' ')) . '):');
        $this->line($twoFactor->qrText($operator, $secret));

        for ($try = 0; $try < self::CODE_TRIES; $try++) {
            $answer = $this->ask('Code from the app');
            $codes = $twoFactor->enable($operator, $secret, is_string($answer) ? $answer : '', time());

            if ($codes !== null) {
                $this->info('Two-factor sign-in is on. Keep these recovery codes safe; each works once:');
                $this->line(implode("\n", $codes));

                return true;
            }

            $this->error('Wrong code.');
        }

        return false;
    }

    private function askPassword(): ?string
    {
        $given = $this->option('password');
        $password = is_string($given) && $given !== '' ? $given : $this->secret('Password (at least 12 characters)');

        if (!is_string($password) || mb_strlen($password) < 12) {
            $this->error('The password needs at least 12 characters.');

            return null;
        }

        if (!(is_string($given) && $given !== '') && $this->secret('Password again') !== $password) {
            $this->error('The passwords differ.');

            return null;
        }

        return $password;
    }
}
