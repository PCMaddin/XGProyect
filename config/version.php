<?php

return [
    'files' => env('APP_VERSION'),

    // Asks updates.xgproyect.org for a newer release (admin home alert and
    // the bell in the admin top bar). Off by default: it contacts an outside
    // server from every admin page.
    'update_check' => (bool) env('XGP_UPDATE_CHECK', false),
];
