<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Dark matter shop
|--------------------------------------------------------------------------
|
| Players of a Pay2Win universe (option premium_mode) buy dark matter here,
| paid through Stripe Checkout and credited by Stripe's webhook. Without the
| Stripe keys, or in a Play2Win universe, there is no shop.
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
];
