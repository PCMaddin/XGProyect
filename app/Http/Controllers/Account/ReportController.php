<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Models\User;
use App\Services\Feedback\BugReports;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * "Report a problem" from any game page: what went wrong, on which page,
 * for the operators (BugReports).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class ReportController extends BaseController
{
    public function __construct(private BugReports $reports)
    {
    }

    public function show(Request $request): View
    {
        $from = BugReports::page($request->string('from')->toString());

        return view('account.report', ['from' => $from]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $data = $request->validate([
            'description' => ['required', 'string', 'min:10', 'max:' . BugReports::MAX_LENGTH],
            'from' => ['nullable', 'string', 'max:500'],
        ]);
        $from = is_string($data['from'] ?? null) ? $data['from'] : '';

        $this->reports->submit($user, $from, $data['description'], (string) $request->userAgent(), time());

        return redirect()->route('account.report', ['from' => BugReports::page($from)])->with('report_sent', true);
    }
}
