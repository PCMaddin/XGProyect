<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| A Universe On Another Version
|--------------------------------------------------------------------------
|
| Universes play on the version of the code they were created with; every
| version in use lies in a directory of its own. A request of a universe on
| another version is served by that version's public/index.php (see
| bootstrap/version-router.php), never by this code.
|
*/

$versionIndex = (require __DIR__.'/../bootstrap/version-router.php')($_SERVER, $_GET, $_COOKIE, __DIR__.'/../storage/framework/versions.php');

if ($versionIndex !== null) {
    $_SERVER['XGP_VERSION_DISPATCHED'] = dirname($versionIndex, 2);
    chdir(dirname($versionIndex, 2));
    require $versionIndex;

    exit;
}

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Check If The Application Is Under Maintenance
|--------------------------------------------------------------------------
|
| If the application is in maintenance / demo mode via the "down" command
| we will load this file so that any pre-rendered content can be shown
| instead of starting the framework, which could cause an exception.
|
*/

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| this application. We just need to utilize it! We'll simply require it
| into the script here so we don't need to manually load our classes.
|
*/

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request using
| the application's HTTP kernel. Then, we will send the response back
| to this client's browser, allowing them to enjoy our application.
|
*/

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);

$response = $kernel->handle(
    $request = Request::capture()
)->send();

$kernel->terminate($request, $response);
