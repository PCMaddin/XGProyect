<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Models\User;
use App\Services\Account\EmailVerification;
use App\Services\Legal\LegalPages;
use Illuminate\Http\Request;

/**
 * What a purchase needs besides a package and a way to pay: a confirmed
 * email address, and the express request to deliver the dark matter at
 * once, giving up the right of withdrawal (digital content). And the legal
 * pages the shop links to.
 */
class PurchaseTerms
{
    public function __construct(private EmailVerification $verification, private LegalPages $legal)
    {
    }

    /**
     * Why nothing can be bought now (a key of game/darkmatter), or null.
     */
    public function refusal(Request $request): ?string
    {
        $user = $request->user();

        if ($user instanceof User && $this->verification->pending($user)) {
            return 'dm_verify_email_first';
        }

        return $request->boolean('withdrawal_waiver') ? null : 'dm_withdrawal_waiver_required';
    }

    /**
     * Which of the withdrawal and terms pages have a text to link to.
     *
     * @return array{withdrawal_link: bool, terms_link: bool}
     */
    public function links(): array
    {
        $written = $this->legal->written();

        return [
            'withdrawal_link' => in_array(LegalPages::WITHDRAWAL, $written, true),
            'terms_link' => in_array(LegalPages::TERMS, $written, true),
        ];
    }
}
