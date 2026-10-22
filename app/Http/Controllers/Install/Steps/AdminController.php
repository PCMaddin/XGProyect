<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install\Steps;

use App\Http\Requests\Install\AdminRequest;
use App\Services\Instances\AdminAccount;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Throwable;

class AdminController extends BaseController
{
    public function __invoke(Request $request): View | Factory
    {
        $route = $request->route();

        session(['last_step' => $route !== null ? $route->getName() : null]);

        return view(
            'install.steps.admin',
            [
                'hideForm' => session()->has('admin_create_success') && session('admin_create_success', false),
            ]
        );
    }

    public function doCheck(AdminRequest $request, AdminAccount $admins): RedirectResponse
    {
        try {
            $admins->create(
                self::text($request->validated('username')),
                self::text($request->validated('email')),
                self::text($request->validated('password')),
                ['ip' => $request->ip(), 'agent' => $request->header('User-Agent'), 'page' => $request->getRequestUri()],
            );
        } catch (Throwable $e) {
            report($e);

            return back()
                ->withInput()
                ->with('danger', __('install/install.admin_create_fail') . (config('app.debug') ? ' (' . $e->getMessage() . ')' : ''));
        }

        session()->put('admin_create_success', true);

        return back()
            ->with('success', __('install/install.admin_create_success'));
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
