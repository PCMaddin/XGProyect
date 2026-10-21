<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operator;

use App\Models\Operator;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\VersionFetcher;
use App\Services\Instances\Versions;
use App\Services\Operators\OperatorLog;
use App\Services\Operators\OperatorLogin;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use LogicException;
use RuntimeException;

/**
 * /operator/versions: the versions of the code on the server, as
 * xgp:versions, xgp:versions:fetch, xgp:versions:use and
 * xgp:instance:upgrade do on the command line. A fetch runs in the
 * background; its log is shown here.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class VersionsController extends BaseController
{
    public function __construct(
        private Versions $versions,
        private VersionFetcher $fetcher,
        private InstanceRegistry $registry,
        private OperatorLog $log,
    ) {
    }

    public function index(): View
    {
        $use = $this->versions->inUse();
        $available = $this->versions->available();
        $rows = [];

        foreach ($available as $version) {
            $rows[] = ['version' => $version, 'running' => $version === $this->versions->current(), 'missing' => false, 'directory' => (string) $this->versions->directory($version), 'universes' => $use[$version] ?? []];
        }

        foreach ($use as $version => $slugs) {
            if ($this->versions->directory($version) === null) {
                $rows[] = ['version' => $version, 'running' => false, 'missing' => true, 'directory' => '', 'universes' => $slugs];
            }
        }

        return view('operator.versions', [
            'rows' => $rows,
            'root' => $this->versions->root(),
            'current' => $this->versions->current(),
            'switchable' => $this->versions->servesFromRoot(),
            'fetching' => $this->fetcher->inProgress(),
            'log' => $this->fetcher->log(),
            'upgrades' => $this->upgrades($available),
        ]);
    }

    public function fetch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ref' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'],
            'repo' => ['nullable', 'string', 'max:500', 'regex:/^(https:\/\/|git@|ssh:\/\/|\/)[^\s]+$/'],
        ]);

        try {
            $this->fetcher->fetchInBackground($data['ref'] ?? null, $data['repo'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['ref' => $e->getMessage()]);
        }

        $this->record($request, OperatorLog::VERSION_FETCH, $data['ref'] ?? 'newest tag', ['repo' => $data['repo'] ?? 'origin']);

        return redirect()->route('operator.versions')->with('status', __('operator/operator.op_fetch_started'));
    }

    public function use(Request $request): RedirectResponse
    {
        $version = (string) $request->validate(['version' => ['required', 'string', 'regex:' . Versions::PATTERN]])['version'];

        if (!$this->versions->servesFromRoot()) {
            return back()->withErrors(['version' => __('operator/operator.op_use_unavailable')]);
        }

        if (Artisan::call('xgp:versions:use', ['version' => $version]) !== 0) {
            return back()->withErrors(['version' => trim(Artisan::output())]);
        }

        $this->record($request, OperatorLog::VERSION_USED, $version);

        return redirect()->route('operator.versions')->with('status', __('operator/operator.op_used', ['version' => $version]));
    }

    public function prune(Request $request): RedirectResponse
    {
        try {
            $deleted = $this->versions->prune();
        } catch (RuntimeException $e) {
            return back()->withErrors(['version' => $e->getMessage()]);
        }

        if ($deleted !== []) {
            $this->record($request, OperatorLog::VERSIONS_PRUNED, implode(', ', $deleted));
        }

        return redirect()->route('operator.versions')->with('status', $deleted === [] ? __('operator/operator.op_pruned_none') : __('operator/operator.op_pruned', ['versions' => implode(', ', $deleted)]));
    }

    /**
     * The universes that could move to a newer version, with the versions
     * they could move to.
     *
     * @param list<string> $available
     *
     * @return list<array{instance: Instance, targets: list<string>}>
     */
    private function upgrades(array $available): array
    {
        $upgrades = [];

        foreach ($this->registry->all() as $instance) {
            $from = $instance->version !== '' ? $instance->version : $this->versions->current();
            $targets = array_values(array_filter($available, static fn (string $version): bool => version_compare($version, $from, '>')));

            if ($instance->hasDatabase() && $targets !== []) {
                $upgrades[] = ['instance' => $instance, 'targets' => $targets];
            }
        }

        return $upgrades;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(Request $request, string $action, string $subject, array $details = []): void
    {
        $operator = Auth::guard(OperatorLogin::GUARD)->user();

        if (!$operator instanceof Operator) {
            throw new LogicException('operator.auth lets only operators in');
        }

        $this->log->record($operator, $operator->email, $action, (string) $request->ip(), $subject, $details);
    }
}
