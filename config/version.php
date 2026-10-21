<?php

return [
    // the version of the code. Raise it with every release: universes play
    // on the version they were created with, and every version in use lies
    // in a directory of its name (docs/universes.md, "Versionen je
    // Universum"). Never from the .env: all versions share that file.
    'files' => '4.0.0',

    // Asks updates.xgproyect.org for a newer release (admin home alert and
    // the bell in the admin top bar). Off by default: it contacts an outside
    // server from every admin page.
    'update_check' => (bool) env('XGP_UPDATE_CHECK', false),
];
