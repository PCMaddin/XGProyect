<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Models\User;
use App\Services\Account\EmailVerification;
use App\Services\Instances\InstanceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * The link from the mail confirms the address (signed, for this address
 * only; it works without signing in, on any device). A signed-in player
 * whose mail did not arrive asks for it again.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class VerifyEmailController extends BaseController
{
    public function __construct(private EmailVerification $verification, private InstanceContext $context)
    {
    }

    public function verify(string $id, string $hash): View
    {
        $user = User::find((int) $id);

        // the address changed since the link was sent
        abort_if($user === null || !hash_equals(EmailVerification::hash($user->email), $hash), 403);

        if ($user->email_verified_at === null) {
            $this->verification->confirm($user);
        }

        return view('account.verified', ['game' => $this->context->link(url('game.php?page=overview'))]);
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (!$this->verification->pending($user)) {
            return back();
        }

        $sent = $this->verification->send($user);

        return back()->with('verify_email_status', __($sent ? 'account/verify.vf_resent' : 'account/verify.vf_resend_failed'));
    }
}
