<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Users;

use Illuminate\Http\Request;
use App\Services\Operators\OperatorLog;
use App\Support\Concerns\ReadsMixedValues;
use App\Core\GameObjects\GameObjectRegistry;
use App\Http\Requests\Admin\Users\UserPremiumRequest;
use App\Http\Requests\Admin\Users\UserResearchRequest;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use App\Libraries\StatisticsLibrary;

class UserProgressController extends BaseController
{
    use ReadsMixedValues;

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function showResearch(User $user): View
    {
        $research = DB::table('research')->where('research_user_id', $user->id)->first();

        return view('admin.users_research', [
            'user' => $user,
            'technologies' => $this->buildResearchList((array) ($research ?? [])),
        ]);
    }

    public function updateResearch(UserResearchRequest $request, User $user): RedirectResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        // only the technologies (the prefix let research_user_id and the
        // current research through as well)
        $updates = [];

        foreach (app(GameObjectRegistry::class)->research() as $technology) {
            $level = $validated[$technology->getName()] ?? null;

            if (is_numeric($level)) {
                $updates[$technology->getName()] = (int) $level;
            }
        }

        if (!empty($updates)) {
            DB::table('research')->where('research_user_id', $user->id)->update($updates);
        }

        (new StatisticsLibrary())->rebuildPoints($user->id, 0, 'research');

        session()->flash('success', __('admin/users.us_all_ok_message'));

        return redirect()->route('admin.users.research', $user->id);
    }

    public function showPremium(User $user): View
    {
        $premium = DB::table('premium')->where('premium_user_id', $user->id)->first();
        $dateFormat = $this->dateFormat();

        return view('admin.users_premium', [
            'user' => $user,
            'dark_matter' => (int) ($premium->premium_dark_matter ?? 0),
            'officers' => $this->buildPremiumList((array) ($premium ?? []), $dateFormat),
        ]);
    }

    public function updatePremium(UserPremiumRequest $request, User $user): RedirectResponse
    {
        $currentPremium = (array) (DB::table('premium')->where('premium_user_id', $user->id)->first() ?? []);
        $updates = [];

        if ($request->filled('premium_dark_matter')) {
            $updates['premium_dark_matter'] = $request->integer('premium_dark_matter');
        }

        // only the officers (the prefix let premium_user_id through as well)
        foreach (app(GameObjectRegistry::class)->officers() as $officer) {
            $key = $officer->getName();

            if (!$request->has($key)) {
                continue;
            }

            $updates[$key] = match ($request->integer($key)) {
                1 => 0,
                2 => time() + (3600 * 24 * 7),
                3 => time() + (3600 * 24 * 30 * 3),
                default => $currentPremium[$key] ?? 0,
            };
        }

        if (!empty($updates)) {
            DB::table('premium')->where('premium_user_id', $user->id)->update($updates);
        }

        $before = $currentPremium['premium_dark_matter'] ?? null;

        if (isset($updates['premium_dark_matter']) && (is_numeric($before) ? (int) $before : null) !== $updates['premium_dark_matter']) {
            $this->recordDarkMatter($request, $user, is_numeric($before) ? (int) $before : 0, $updates['premium_dark_matter']);
        }

        session()->flash('success', __('admin/users.us_all_ok_message'));

        return redirect()->route('admin.users.premium', $user->id);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildResearchList(array $row): array
    {
        $list = [];
        $skip = 3;

        foreach ($row as $key => $value) {
            if (!str_starts_with($key, 'research_')) {
                continue;
            }
            if ($skip-- > 0) {
                continue;
            }

            $list[] = [
                'field' => $key,
                'label' => (string) __('admin/users.us_user_' . $key),
                'level' => self::asInt($value),
            ];
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildPremiumList(array $row, string $dateFormat): array
    {
        $list = [];

        foreach ($row as $key => $value) {
            if (!str_starts_with($key, 'premium_') || in_array($key, ['premium_dark_matter', 'premium_user_id'], true)) {
                continue;
            }

            $labelKey = 'admin/users.us_user_' . $key;
            $label = __($labelKey);

            if ($label === $labelKey) {
                continue;
            }

            $expire = self::asInt($value);

            $list[] = [
                'field' => $key,
                'label' => (string) $label,
                'expire' => $expire,
                'active' => $expire > 0 && $expire > time(),
                'status_text' => ($expire === 0 || $expire < time())
                    ? (string) __('admin/users.us_user_premium_inactive')
                    : (string) __('admin/users.us_user_premium_active_until') . date($dateFormat, $expire),
            ];
        }

        return $list;
    }

    private function dateFormat(): string
    {
        return $this->settings->getString('date_format') ?: 'Y-m-d';
    }

    /**
     * Dark matter is worth money: who gave whom how much goes to the
     * operators' log in the base database, out of reach of the admin.
     */
    private function recordDarkMatter(Request $request, User $player, int $from, int $to): void
    {
        $admin = $request->user();
        $email = $admin instanceof User ? $admin->email : '';
        $details = ['admin_id' => $admin instanceof User ? $admin->id : 0, 'player_id' => $player->id, 'player' => $player->name, 'from' => $from, 'to' => $to];

        app(OperatorLog::class)->recordAdmin($email, OperatorLog::ADMIN_DARK_MATTER, (string) $request->ip(), $details);
    }
}
