<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reverse proxies the game trusts
|--------------------------------------------------------------------------
|
| Behind a proxy that speaks HTTP to the game (Cloudflare, a load balancer,
| nginx in front of php-fpm on another host), the game sees the proxy's
| address: every player would share one address for the rate limits, the
| sign-in log and the cheat detection. Name the proxies here, and the
| X-Forwarded-* headers they set count: "203.0.113.7,10.0.0.0/8", or "*"
| for whatever address calls (only when nothing else can reach the game).
|
| Empty (the default, and the Docker stack: Caddy hands requests to php-fpm
| with the player's own address): no header is trusted, a forged
| X-Forwarded-For changes nothing.
|
*/

return [
    'proxies' => (string) env('XGP_TRUSTED_PROXIES', '') !== '' ? (string) env('XGP_TRUSTED_PROXIES') : null,
];
