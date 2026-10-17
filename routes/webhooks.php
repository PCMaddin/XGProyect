<?php

declare(strict_types=1);

use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Calls of payment providers: no session, no CSRF token, no login (the
| middleware group "webhook"); each controller checks the call's signature.
*/

Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');
