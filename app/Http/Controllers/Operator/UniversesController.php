<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operator;

use App\Models\Operator;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\RewardPlan;
use App\Services\Instances\SeasonPayouts;
use App\Services\Instances\SeasonResults;
use App\Services\Instances\Versions;
use App\Services\Operators\OperatorLog;
use App\Services\Operators\OperatorLogin;
use App\Services\Premium\DarkMatterShop;
use App\Services\Premium\TokenSpec;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * /operator/universes: what xgp:instance:* does on the command line, in the
 * browser. Every change is logged with the operator.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class UniversesController extends BaseController
{
    private const EVENTS_SHOWN = 30;

    public function __construct(
        private InstanceRegistry $registry,
        private InstanceProvisioner $provisioner,
        private InstanceContext $context,
        private SeasonPayouts $payouts,
        private OperatorLog $log,
        private Versions $versions,
    ) {
    }

    public function index(): View
    {
        return view('operator.universes', [
            'universes' => array_map(fn (Instance $instance): array => [
                'instance' => $instance,
                'rewards' => $this->registry->rewardPlan($instance->slug),
            ], $this->registry->all()),
            'events' => $this->log->latest(self::EVENTS_SHOWN),
        ]);
    }

    public function create(): View
    {
        return view('operator.universe_create', [
            'methods' => DarkMatterShop::METHODS,
            'admin_email' => self::operator()->email,
            'versions' => $this->versions->available(),
            'current' => $this->versions->current(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'regex:' . InstanceProvisioner::SLUG],
            'name' => ['required', 'string', 'max:64'],
            'mode' => ['required', Rule::in([Instance::PAY2WIN, Instance::PLAY2WIN])],
            'payments' => ['nullable', 'array'],
            'payments.*' => ['string', Rule::in(DarkMatterShop::METHODS)],
            'ends' => ['nullable', 'date', 'after:now'],
            'bots' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'admin_name' => ['required', 'string', 'max:64'],
            'admin_email' => ['required', 'email', 'max:254'],
            'admin_password' => ['required', 'string', 'min:8', 'max:200'],
            'version' => ['nullable', 'string', Rule::in($this->versions->available())],
        ]);
        $now = time();
        $version = $data['version'] ?? $this->versions->current();
        $instance = new Instance($data['slug'], $data['name'], $this->provisioner->databaseFor($data['slug']), $data['mode'], Instance::OPEN, $now, self::time($data['ends'] ?? null), null, '', $version);
        $payments = implode(',', self::strings($data['payments'] ?? []));

        // a new database with all tables takes a while
        set_time_limit(600);

        try {
            $version === $this->versions->current()
                ? $this->provisioner->create($instance, $data['admin_name'], $data['admin_email'], $data['admin_password'], ['payment_methods' => $this->provisioner->paymentMethods($payments)], (int) ($data['bots'] ?? 0))
                : $this->createOnVersion($instance, $payments, (int) ($data['bots'] ?? 0), $data['admin_name'], $data['admin_email'], $data['admin_password']);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return back()->withInput($request->except('admin_password'))->withErrors(['slug' => $e->getMessage()]);
        }

        $this->record($request, OperatorLog::CREATED, $instance->slug, ['mode' => $instance->mode, 'payments' => $payments, 'ends_at' => $instance->endsAt, 'database' => $instance->database, 'version' => $version]);

        return redirect()->route('operator.universes.edit', $instance->slug)->with('status', __('operator/operator.op_created', ['name' => $instance->name]));
    }

    /**
     * An older version sets the universe up with its own code
     * (xgp:instance:create of its directory; the password through the
     * environment, never on the command line).
     */
    private function createOnVersion(Instance $instance, string $payments, int $bots, string $adminName, string $adminEmail, string $adminPassword): void
    {
        $this->versions->artisan($instance->version, 'xgp:instance:create', [
            $instance->slug, '--name=' . $instance->name, '--mode=' . $instance->mode, '--payments=' . $payments, '--bots=' . $bots,
            '--ends=' . ($instance->endsAt === null ? '' : date('Y-m-d H:i:s', $instance->endsAt)),
            '--admin-name=' . $adminName, '--admin-email=' . $adminEmail,
        ], ['XGP_ADMIN_PASSWORD' => $adminPassword]);
        $this->registry->refresh();
        $this->versions->writeMap();
    }

    public function edit(string $slug): View | RedirectResponse
    {
        $instance = $this->instance($slug);

        if (!$instance->hasDatabase()) {
            return redirect()->route('operator.universes')->withErrors(['universe' => __('operator/operator.op_archived_note')]);
        }

        return view('operator.universe_edit', [
            'instance' => $instance,
            'payments' => $this->context->runIn($instance, static fn (): array => app(DarkMatterShop::class)->allowedMethods()),
            'methods' => array_map(static fn (string $method): array => ['name' => $method, 'ready' => app(DarkMatterShop::class)->configured($method)], DarkMatterShop::METHODS),
            'plan' => $this->registry->rewardPlan($slug) ?? RewardPlan::configured(),
            'categories' => array_keys(SeasonResults::CATEGORIES),
        ]);
    }

    public function update(Request $request, string $slug): RedirectResponse
    {
        $instance = $this->instance($slug);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'mode' => ['required', Rule::in([Instance::PAY2WIN, Instance::PLAY2WIN])],
            'status' => ['required', Rule::in([Instance::OPEN, Instance::CLOSED])],
            'payments' => ['nullable', 'array'],
            'payments.*' => ['string', Rule::in(DarkMatterShop::METHODS)],
            'ends' => ['nullable', 'date'],
            'rewards' => ['nullable', 'string', 'max:500'],
            'shares' => ['nullable', 'string', 'max:100'],
        ]);
        $changes = [
            'name' => $data['name'],
            'mode' => $data['mode'],
            'status' => $data['status'],
            'payments' => implode(',', self::strings($data['payments'] ?? [])),
            'ends_at' => self::time($data['ends'] ?? null),
        ];

        try {
            $changed = $this->provisioner->change($instance, $changes);
            $plan = $this->provisioner->changeRewards($changed, $data['rewards'] ?? '', $data['shares'] ?? '');
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        $this->record($request, $instance->status === Instance::OPEN && $changed->status === Instance::CLOSED ? OperatorLog::CLOSED : OperatorLog::CHANGED, $slug, $changes + ['rewards' => $plan->pools, 'shares' => $plan->shares]);

        return redirect()->route('operator.universes.edit', $slug)->with('status', __('operator/operator.op_saved'));
    }

    public function close(Request $request, string $slug): RedirectResponse
    {
        $instance = $this->instance($slug);

        if ($instance->status === Instance::OPEN) {
            $this->provisioner->close($instance);
            $this->record($request, OperatorLog::CLOSED, $slug);
        }

        return redirect()->route('operator.universes.season', $slug)->with('status', __('operator/operator.op_closed', ['name' => $instance->name]));
    }

    /**
     * The universe moves to a newer version (xgp:instance:upgrade).
     */
    public function upgrade(Request $request, string $slug): RedirectResponse
    {
        $instance = $this->instance($slug);
        $version = (string) $request->validate(['version' => ['required', 'string', 'regex:' . Versions::PATTERN]])['version'];

        // the migrations of a large universe take a while
        set_time_limit(600);

        if (Artisan::call('xgp:instance:upgrade', ['slug' => $slug, '--to' => $version]) !== 0) {
            return redirect()->route('operator.versions')->withErrors(['upgrade' => trim(Artisan::output())]);
        }

        $this->record($request, OperatorLog::UPGRADED, $slug, ['from' => $instance->version, 'to' => $version]);

        return redirect()->route('operator.versions')->with('status', __('operator/operator.op_upgraded', ['name' => $instance->name, 'version' => $version]));
    }

    public function season(string $slug): View
    {
        $instance = $this->instance($slug);
        $token = TokenSpec::game();

        return view('operator.season', [
            'instance' => $instance,
            'prizes' => $this->payouts->overview($slug),
            'token' => $token,
            'shop_account' => app(DarkMatterShop::class)->waxAccount(),
            'reserved' => $token === null ? [] : $this->payouts->reserved($slug, $token),
        ]);
    }

    public function payout(Request $request, string $slug): RedirectResponse
    {
        $instance = $this->instance($slug);
        $token = TokenSpec::game();

        if ($token === null || app(DarkMatterShop::class)->waxAccount() === '' || $instance->isOpen()) {
            return redirect()->route('operator.universes.season', $slug)->withErrors(['payout' => __('operator/operator.op_payout_unavailable')]);
        }

        $reserved = $this->payouts->reserve($slug, $token, time());
        $this->record($request, OperatorLog::PAYOUT, $slug, ['prizes' => count($reserved)]);

        return redirect()->route('operator.universes.season', $slug);
    }

    public function release(Request $request, string $slug): RedirectResponse
    {
        $this->instance($slug);
        $released = $this->payouts->release($slug);
        $this->record($request, OperatorLog::RELEASED, $slug, ['prizes' => $released]);

        return redirect()->route('operator.universes.season', $slug)->with('status', __('operator/operator.op_released', ['count' => $released]));
    }

    private function instance(string $slug): Instance
    {
        $instance = $this->registry->find($slug);

        if ($instance === null) {
            abort(404);
        }

        return $instance;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(Request $request, string $action, string $slug, array $details = []): void
    {
        $operator = self::operator();
        $this->log->record($operator, $operator->email, $action, (string) $request->ip(), $slug, $details);
    }

    private static function operator(): Operator
    {
        $operator = Auth::guard(OperatorLogin::GUARD)->user();

        if (!$operator instanceof Operator) {
            throw new LogicException('operator.auth lets only operators in');
        }

        return $operator;
    }

    private static function time(mixed $date): ?int
    {
        $time = is_string($date) && $date !== '' ? strtotime($date) : false;

        return $time === false ? null : $time;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }
}
