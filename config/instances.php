<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Universes
|--------------------------------------------------------------------------
|
| A new universe every month (xgp:instance:monthly, run by the scheduler on
| the first of the month when enabled). Name and slug take :year, :month and
| :number (the count of universes so far plus one). Options are given as
| name=value pairs separated by commas (game_speed=5000,fleet_speed=5000).
| keep_open closes all but the newest universes; 0 keeps every one open.
|
*/

return [
    'monthly' => [
        'enabled' => (bool) env('XGP_MONTHLY_ENABLED', false),
        'slug' => env('XGP_MONTHLY_SLUG', ':year-:month'),
        'name' => env('XGP_MONTHLY_NAME', 'Universe :year-:month'),
        'mode' => env('XGP_MONTHLY_MODE', 'play2win'),
        // ways to pay in a pay2win universe: stripe, wax or stripe,wax
        'payments' => env('XGP_MONTHLY_PAYMENTS', 'stripe'),
        'options' => env('XGP_MONTHLY_OPTIONS', ''),
        'keep_open' => (int) env('XGP_MONTHLY_KEEP_OPEN', 0),
        'admin' => [
            'name' => env('XGP_MONTHLY_ADMIN_NAME', ''),
            'email' => env('XGP_MONTHLY_ADMIN_EMAIL', ''),
            'password' => env('XGP_MONTHLY_ADMIN_PASSWORD', ''),
        ],
    ],
];
