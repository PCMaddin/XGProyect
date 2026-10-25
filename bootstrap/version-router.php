<?php

/*
|--------------------------------------------------------------------------
| Versions side by side
|--------------------------------------------------------------------------
|
| A universe plays on the version of the code it was created with (or
| moved to with xgp:instance:upgrade); every version in use lies in a
| directory of its own (xgp:versions:sync, config instances.versions_path).
| Before the framework starts, public/index.php asks here whether the
| request belongs to a universe on another version: then that version's
| public/index.php serves it, with its own code and its own vendor/.
|
| The universe comes from the request as the lobby and the mail links name
| it: the query parameter "universe", else the lobby cookie; Stripe's
| webhook names it in the session's metadata. The map was written by
| xgp:versions:sync (storage/framework/versions.php) and lists only the
| universes on other versions. A version is a plain directory name.
|
| Returns the index.php to hand over to, or null to serve here.
*/

return static function (array $server, array $get, array $cookie, string $mapFile): ?string {
    if (!empty($server['XGP_VERSION_DISPATCHED']) || !is_file($mapFile)) {
        return null;
    }

    $map = require $mapFile;

    if (!is_array($map) || !is_array($map['universes'] ?? null) || !is_string($map['root'] ?? null)) {
        return null;
    }

    $slug = $get['universe'] ?? $cookie['xgp_universe'] ?? null;
    $path = parse_url($server['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $path = is_string($path) ? '/' . trim($path, '/') : '/';

    if ($path === '/stripe/webhook' && ($server['REQUEST_METHOD'] ?? '') === 'POST') {
        $event = json_decode((string) file_get_contents('php://input'), true);
        $slug = is_array($event) ? ($event['data']['object']['metadata']['universe'] ?? null) : null;
    } elseif (preg_match('#^/(universes|legal|operator|install|stripe|up)(/|$)#', $path) === 1) {
        // the lobby, the legal pages, the operators' area and the installer
        // belong to no universe: the running version serves them, whatever
        // universe the cookie names
        return null;
    }

    if (!is_string($slug) || $slug === '') {
        return null;
    }

    $version = $map['universes'][$slug] ?? null;

    if (!is_string($version) || $version === ($map['current'] ?? null) || preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,31}$/', $version) !== 1) {
        return null;
    }

    $index = $map['root'] . '/' . $version . '/public/index.php';

    return is_file($index) ? $index : null;
};
