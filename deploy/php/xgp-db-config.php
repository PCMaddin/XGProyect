<?php

// The container's database comes from the environment (deploy/.env); the
// web installer, which writes this file, is not used there: xgp:install.
config([
    'database.default' => 'mysql',
    'SECRETWORD' => env('XGP_SECRETWORD'),
    'INSTALLED' => 'true',
]);
