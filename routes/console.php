<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// the universe of the month, see config/instances.php; needs the scheduler
// in the server's crontab: * * * * * php artisan schedule:run
Schedule::command('xgp:instance:monthly')
    ->monthlyOn(1, '00:05')
    ->when(static fn (): bool => (bool) config('instances.monthly.enabled'))
    ->withoutOverlapping();
