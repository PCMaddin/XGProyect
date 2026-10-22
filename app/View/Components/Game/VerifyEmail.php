<?php

declare(strict_types=1);

namespace App\View\Components\Game;

use App\Models\User;
use App\Services\Account\EmailVerification;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

/**
 * The reminder on every game page while the player's email address is not
 * confirmed: where the link went, until when, and a button to send it again.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class VerifyEmail extends Component
{
    public function __construct(private EmailVerification $verification)
    {
    }

    public function render(): View | Htmlable | Closure | string
    {
        $user = Auth::user();

        if (!$user instanceof User || !$this->verification->pending($user)) {
            return '';
        }

        return view('components.game.verify-email', [
            'email' => $user->email,
            'deadline' => $this->verification->deadline($user),
        ]);
    }
}
