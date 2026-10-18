<?php

return [
    // the version of the code; APP_VERSION only for an older .env
    'files' => env('APP_VERSION', '4.0.0'),

    // Asks updates.xgproyect.org for a newer release (admin home alert and
    // the bell in the admin top bar). Off by default: it contacts an outside
    // server from every admin page.
    'update_check' => (bool) env('XGP_UPDATE_CHECK', false),
];
