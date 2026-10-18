<?php

declare(strict_types=1);

// The operators' area on /operator: managing the universes in the browser.
// Accounts: php artisan xgp:operator:create <e-mail>. Two-factor sign-in is
// always on and set up at the first sign-in.
return [
    // false: no /operator at all (the command line still works)
    'web' => (bool) env('XGP_OPERATOR_WEB', true),

    // only from these addresses or networks, comma-separated
    // (e.g. "203.0.113.7,10.0.0.0/8"); empty: from anywhere
    'allowed_ips' => (string) env('XGP_OPERATOR_IPS', ''),

    // signed out after this long without a request, and after this long in any case
    'idle_minutes' => 30,
    'max_hours' => 8,

    // a password and then a code: this many tries
    'attempts' => 5,
];
