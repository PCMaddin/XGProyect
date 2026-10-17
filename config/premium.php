<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Dark matter shop
|--------------------------------------------------------------------------
|
| Players of a Pay2Win universe (option premium_mode) buy dark matter here,
| paid the ways the universe allows (option payment_methods: stripe, wax)
| and that are set up below: Stripe Checkout, credited by Stripe's webhook,
| or a WAX transfer, credited by xgp:wax:check. Without a way to pay, or in
| a Play2Win universe, there is no shop.
|
| Prices are in the smallest unit of the currency (cents). The package id
| is what the order stores and the shop posts: change the amount or the
| price as you like, but give a package a new id rather than reusing one.
|
*/

return [
    'currency' => strtolower((string) env('STRIPE_CURRENCY', 'eur')),

    'packages' => [
        ['id' => 'dm_10k', 'dark_matter' => 10000, 'price' => 199],
        ['id' => 'dm_50k', 'dark_matter' => 50000, 'price' => 799],
        ['id' => 'dm_120k', 'dark_matter' => 120000, 'price' => 1699],
        ['id' => 'dm_300k', 'dark_matter' => 300000, 'price' => 3499],
    ],

    'stripe' => [
        // the secret API key (sk_live_… or sk_test_…)
        'secret' => (string) env('STRIPE_SECRET', ''),
        // the signing secret of the webhook endpoint (whsec_…)
        'webhook_secret' => (string) env('STRIPE_WEBHOOK_SECRET', ''),
        'api_url' => 'https://api.stripe.com/v1',
        // a signed event older than this (seconds) is refused (replays)
        'tolerance' => 300,
    ],

    'wax' => [
        // the shop's WAX account (a-z, 1-5, dots; up to 12); empty: no WAX
        'account' => (string) env('XGP_WAX_ACCOUNT', ''),
        // a Hyperion history server of the WAX chain
        'api_url' => rtrim((string) env('XGP_WAX_API', 'https://wax.eosusa.io'), '/'),
        // the price of 1 WAX in the shop's currency: {"wax":{"eur":0.0043}}
        'rate_url' => (string) env('XGP_WAX_RATE_URL', 'https://api.coingecko.com/api/v3/simple/price?ids=wax&vs_currencies=:currency'),
        // the rate is read again after this many seconds
        'rate_cache' => 300,
        // added to the converted price (percent), for the rate moving
        'markup' => (float) env('XGP_WAX_MARKUP', 0),
        // a quote holds this long: later transfers are left to the admin
        'quote_minutes' => 30,
        // transfers looked at on the first check (hours back)
        'lookback_hours' => 24,
    ],
];
