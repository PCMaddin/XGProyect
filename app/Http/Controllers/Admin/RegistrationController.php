<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\RegistrationRequest;
use App\Models\User;
use App\Services\Account\Invitations;
use App\Services\Instances\InstanceContext;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegistrationController extends AdminSettingsController
{
    private const BOOL_SETTINGS = [
        'reg_enable',
        'reg_welcome_message',
        'reg_welcome_email',
        'reg_verify_email',
        'reg_invite_only',
    ];

    public function __construct(SettingsService $settings, private Invitations $invitations, private InstanceContext $context)
    {
        parent::__construct($settings);
    }

    public function index(): View
    {
        return $this->view('admin.registration', $this->buildViewData());
    }

    public function update(RegistrationRequest $request): RedirectResponse
    {
        foreach (self::BOOL_SETTINGS as $key) {
            $this->settings->write($key, $request->boolean($key) ? 1 : 0);
        }

        $this->settings->write('reg_max_players', max(0, $request->integer('reg_max_players')));

        return $this->saved('admin.registration', 'admin/registration.ur_all_ok_message');
    }

    /**
     * A new invitation code, good for a number of players and maybe days.
     */
    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'uses' => ['required', 'integer', 'min:1', 'max:10000'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'note' => ['nullable', 'string', 'max:100'],
        ]);
        $now = time();
        $days = isset($data['days']) ? (int) $data['days'] : 0;
        $admin = $request->user();

        $code = $this->invitations->create(
            (int) $data['uses'],
            $days > 0 ? $now + $days * 86400 : null,
            is_string($data['note'] ?? null) ? $data['note'] : '',
            $admin instanceof User ? $admin->name : 'admin',
            $now
        );

        return redirect()->route('admin.registration')->with('success', __('admin/registration.ur_invite_created', ['code' => $code]));
    }

    public function revoke(string $invitation): RedirectResponse
    {
        $this->invitations->revoke((int) $invitation);

        return redirect()->route('admin.registration')->with('success', __('admin/registration.ur_invite_revoked'));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(): array
    {
        return array_combine(
            self::BOOL_SETTINGS,
            array_map(fn ($key) => $this->settings->getBool($key), self::BOOL_SETTINGS),
        ) + [
            'reg_max_players' => $this->settings->getInt('reg_max_players'),
            'invitations' => array_map(fn (array $invitation): array => $invitation + [
                'link' => $this->context->link(url('/') . '?invite=' . $invitation['code']),
            ], $this->invitations->all()),
        ];
    }
}
