<?php

declare(strict_types=1);

use App\Http\Controllers\GameController;
use Illuminate\Support\Facades\Route;

Route::any('{path}', GameController::class)->where('path', '.*');
