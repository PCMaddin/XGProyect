<?php

/*
|--------------------------------------------------------------------------
| The folder "current" without a php-fpm reload
|--------------------------------------------------------------------------
|
| A server without Docker serves <versions path>/current/public, and
| xgp:versions:use points the link "current" at another version. PHP keeps
| resolved paths for a while (realpath_cache_ttl, two minutes by default),
| so right after the switch a request can still land in the old folder.
| public/index.php asks here first: the link itself is read fresh (readlink
| bypasses that cache), and when it names another folder, that folder's
| public/index.php serves the request. No reload, no root rights.
|
| Returns the index.php to hand over to, or null to serve here. Never for a
| request the universe router handed to an older version on purpose, and
| never twice.
*/

return static function (string $publicDir, array $server): ?string {
    if (!empty($server['XGP_VERSION_DISPATCHED']) || !empty($server['XGP_CURRENT_FOLLOWED'])) {
        return null;
    }

    $here = dirname($publicDir);
    $link = dirname($here) . '/current';

    if (!is_link($link)) {
        return null;
    }

    $target = readlink($link);

    if (!is_string($target) || $target === '') {
        return null;
    }

    // a relative link names a folder beside it
    $target = rtrim(str_starts_with($target, '/') ? $target : dirname($link) . '/' . $target, '/');

    if ($target === $here || !is_file($target . '/public/index.php')) {
        return null;
    }

    return $target . '/public/index.php';
};
