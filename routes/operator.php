<?php

declare(strict_types=1);

use App\Http\Controllers\Operator\AccountController;
use App\Http\Controllers\Operator\ErrorsController;
use App\Http\Controllers\Operator\LegalController;
use App\Http\Controllers\Operator\LoginController;
use App\Http\Controllers\Operator\ReportsController;
use App\Http\Controllers\Operator\UniversesController;
use App\Services\Legal\LegalPages;
use Illuminate\Support\Facades\Route;

// /operator: the operators of the server manage the universes (see config/operators.php)
Route::get('/', [LoginController::class, 'show'])->name('operator.login');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:operator-login')->name('operator.login.submit');
Route::get('/code', [LoginController::class, 'showCode'])->name('operator.code');
Route::post('/code', [LoginController::class, 'verify'])->middleware('throttle:operator-login')->name('operator.code.verify');
Route::post('/logout', [LoginController::class, 'logout'])->name('operator.logout');

Route::middleware('operator.auth')->group(function () {
    Route::get('/universes', [UniversesController::class, 'index'])->name('operator.universes');
    Route::get('/universes/create', [UniversesController::class, 'create'])->name('operator.universes.create');
    Route::post('/universes', [UniversesController::class, 'store'])->name('operator.universes.store');
    Route::get('/universes/{slug}', [UniversesController::class, 'edit'])->name('operator.universes.edit');
    Route::post('/universes/{slug}', [UniversesController::class, 'update'])->name('operator.universes.update');
    Route::post('/universes/{slug}/close', [UniversesController::class, 'close'])->name('operator.universes.close');
    Route::get('/universes/{slug}/season', [UniversesController::class, 'season'])->name('operator.universes.season');
    Route::post('/universes/{slug}/payout', [UniversesController::class, 'payout'])->name('operator.universes.payout');
    Route::post('/universes/{slug}/release', [UniversesController::class, 'release'])->name('operator.universes.release');
    Route::get('/errors', [ErrorsController::class, 'index'])->name('operator.errors');
    Route::get('/errors/{group}', [ErrorsController::class, 'show'])->whereNumber('group')->name('operator.errors.show');
    Route::post('/errors/{group}', [ErrorsController::class, 'update'])->whereNumber('group')->name('operator.errors.update');
    Route::get('/reports', [ReportsController::class, 'index'])->name('operator.reports');
    Route::post('/reports/{report}', [ReportsController::class, 'update'])->whereNumber('report')->name('operator.reports.update');
    Route::get('/legal', [LegalController::class, 'index'])->name('operator.legal');
    Route::get('/legal/{page}/{locale}', [LegalController::class, 'edit'])
        ->whereIn('page', LegalPages::PAGES)->name('operator.legal.edit');
    Route::post('/legal/{page}/{locale}', [LegalController::class, 'update'])
        ->whereIn('page', LegalPages::PAGES)->name('operator.legal.update');
    Route::get('/account', [AccountController::class, 'show'])->name('operator.account');
    Route::post('/account/password', [AccountController::class, 'password'])->name('operator.account.password');
    Route::post('/account/codes', [AccountController::class, 'codes'])->name('operator.account.codes');
});
