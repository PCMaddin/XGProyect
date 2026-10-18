<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cheat detection
|--------------------------------------------------------------------------
|
| xgp:cheat:scan looks for signs of multiple accounts, pushing and scripts
| and lists them for the admin (Admin → Information → Suspicions). It only
| points: the admin decides, and only "excluded" accounts lose their place
| in the season's rankings and its prizes.
|
*/

return [
    // how far back the signs are looked for (days)
    'window_days' => (int) env('XGP_CHEATS_WINDOW_DAYS', 30),
    // resources sent to a stronger player, minus what came back, from here on
    'pushing_resources' => (int) env('XGP_CHEATS_PUSHING_RESOURCES', 100000),
    // active in this many hours of a day …
    'active_hours' => (int) env('XGP_CHEATS_ACTIVE_HOURS', 20),
    // … on this many days of the last week
    'active_days' => (int) env('XGP_CHEATS_ACTIVE_DAYS', 3),
    // requests in one hour no person clicks
    'requests_per_hour' => (int) env('XGP_CHEATS_REQUESTS_PER_HOUR', 1200),
    // sign-ins and activity older than this are deleted (days)
    'keep_days' => (int) env('XGP_CHEATS_KEEP_DAYS', 60),
];
