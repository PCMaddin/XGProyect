<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operator;

use App\Services\Feedback\BugReports;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * What players reported from the game, newest first, by status; each is
 * set to seen or fixed here.
 */
class ReportsController extends BaseController
{
    public function __construct(private BugReports $reports)
    {
    }

    public function index(Request $request): View
    {
        $status = $request->query('status', BugReports::NEW);
        $status = is_string($status) && in_array($status, BugReports::STATUSES, true) ? $status : null;
        $page = max(0, $request->integer('page'));

        return view('operator.reports', [
            'status' => $status,
            'page' => $page,
            'reports' => $this->reports->latest($status, $page * BugReports::PAGE_SIZE),
            'counts' => $this->reports->counts(),
        ]);
    }

    public function update(Request $request, string $report): RedirectResponse
    {
        $status = $request->string('status')->toString();

        if (!$this->reports->setStatus((int) $report, $status, time())) {
            return back()->withErrors(['status' => __('operator/operator.op_reports_not_changed')]);
        }

        return back()->with('status', __('operator/operator.op_reports_changed'));
    }
}
