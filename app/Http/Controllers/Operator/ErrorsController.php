<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operator;

use App\Services\Operators\ErrorMonitor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * The errors of every universe, grouped by kind, the latest first; a kind
 * is resolved (it opens again when it comes back) or muted (no mails).
 */
class ErrorsController extends BaseController
{
    public function __construct(private ErrorMonitor $monitor)
    {
    }

    public function index(Request $request): View
    {
        $status = $request->query('status', ErrorMonitor::OPEN);
        $status = is_string($status) && in_array($status, ErrorMonitor::STATUSES, true) ? $status : null;
        $page = max(0, $request->integer('page'));

        return view('operator.errors', [
            'status' => $status,
            'page' => $page,
            'groups' => $this->monitor->groups($status, $page * ErrorMonitor::PAGE_SIZE),
            'counts' => $this->monitor->counts(),
            'mail' => is_string($mail = config('operators.errors_mail')) ? $mail : '',
        ]);
    }

    public function show(string $group): View
    {
        $found = $this->monitor->find((int) $group);
        abort_if($found === null, 404);

        return view('operator.error', ['group' => $found]);
    }

    public function update(Request $request, string $group): RedirectResponse
    {
        if (!$this->monitor->setStatus((int) $group, $request->string('status')->toString())) {
            return back()->withErrors(['status' => __('operator/operator.op_reports_not_changed')]);
        }

        return back()->with('status', __('operator/operator.op_reports_changed'));
    }
}
