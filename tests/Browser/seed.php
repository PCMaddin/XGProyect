<?php

declare(strict_types=1);

/*
 * Prepares a fresh installation for tests/Browser/crawl.mjs: a second
 * player and texts in every place players and admins can write, each one
 * a piece of code (the marker) that sets window.__xss when it runs; an
 * operator with a known two-factor secret. What the crawler needs goes to
 * tests/Browser/.seed.json.
 *
 *   php artisan xgp:install --admin-name=Admin --admin-email=admin@example.com --admin-password=Geheim12345
 *   php tests/Browser/seed.php
 */

use App\Libraries\Functions;
use App\Models\Operator;
use App\Models\User;
use App\Services\Account\EmailVerification;
use App\Services\Account\Invitations;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use App\Services\Feedback\BugReports;
use App\Services\Legal\LegalPages;
use App\Services\Operators\ErrorMonitor;
use App\Services\RegistrationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Laravel's handler prints the error but ends with status 0; the CI has to stop
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error . PHP_EOL);
    exit(1);
});

const MARKER = 'xss"\'><img src=x onerror="window.__xss=(window.__xss||0)+1"><script>window.__xss=(window.__xss||0)+1</script>\x3c';
// the same for short columns (names, titles: 32 characters)
const SHORT = '"\'><img src=x onerror=__xss=1>';
const PASSWORD = 'Geheim12345';
const OPERATOR_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

// a fresh installation holds the admin and nobody else: this creates an
// operator with a known password and secret, never on a game that is played
if (User::count() !== 1 || Operator::query()->exists()) {
    fwrite(STDERR, "Only on a fresh installation (one account, no operator): this seeds test accounts with known passwords.\n");
    exit(1);
}

$now = time();
$admin = User::where('email', 'admin@example.com')->firstOrFail();
$marker = app(RegistrationService::class)->register(['name' => 'Marker', 'email' => 'marker@example.com', 'password' => PASSWORD]);

if (!$marker instanceof User) {
    fwrite(STDERR, "The second player could not be created.\n");
    exit(1);
}

app(EmailVerification::class)->confirm($admin);
app(EmailVerification::class)->confirm($marker);

// the second player next to the admin, with a name and planet that are code
// (what an admin could set, or an older version let through)
$home = DB::table('planets')->where('planet_id', $admin->home_planet_id)->sole(['planet_galaxy', 'planet_system', 'planet_planet']);
$position = (int) $home->planet_planet === 15 ? 14 : (int) $home->planet_planet + 1;
DB::table('planets')->where('planet_user_id', $marker->id)->update(['planet_galaxy' => $home->planet_galaxy, 'planet_system' => $home->planet_system, 'planet_planet' => $position, 'planet_name' => SHORT]);
DB::table('users')->where('id', $marker->id)->update(['name' => SHORT, 'galaxy' => $home->planet_galaxy, 'system' => $home->planet_system, 'planet' => $position]);

$alliance = DB::table('alliance')->insertGetId([
    'alliance_name' => SHORT, 'alliance_tag' => 'x"\'><b>', 'alliance_owner' => $admin->id, 'alliance_register_time' => $now,
    'alliance_ranks' => '[]', 'alliance_description' => MARKER, 'alliance_text' => MARKER, 'alliance_request' => MARKER, 'alliance_web' => 'javascript:window.__xss=1',
]);
DB::table('alliance_statistics')->insert(['alliance_statistic_alliance_id' => $alliance]);
DB::table('users')->whereIn('id', [$admin->id, $marker->id])->update(['ally_id' => $alliance, 'ally_rank_id' => 0]);
DB::table('users')->where('id', $marker->id)->update(['ally_request' => $alliance, 'ally_request_text' => MARKER]);
DB::table('users_statistics')->update(['user_statistic_total_rank' => 1]);

// messages are stored as HTML; who sends one escapes the player's text (see ChatController)
Functions::sendMessage($admin->id, $marker->id, $now, 1, e(SHORT), e(MARKER), e(MARKER));
DB::table('buddys')->insert(['buddy_sender' => $marker->id, 'buddy_receiver' => $admin->id, 'buddy_status' => 0, 'buddy_request_text' => MARKER]);
DB::table('notes')->insert(['note_owner' => $admin->id, 'note_time' => $now, 'note_priority' => 1, 'note_title' => SHORT, 'note_text' => MARKER]);
DB::table('changelog')->insert(['changelog_lang_id' => DB::table('languages')->min('id') ?? 1, 'changelog_version' => '4.0.1', 'changelog_date' => date('Y-m-d'), 'changelog_description' => MARKER]);
DB::table('suspicions')->insert(['user_id' => $marker->id, 'other_user' => $admin->id, 'kind' => 'shared_device', 'score' => 90, 'detail' => MARKER, 'created_at' => $now, 'updated_at' => $now]);

foreach (['en', 'de'] as $locale) {
    app(LegalPages::class)->save(LegalPages::IMPRINT, $locale, '# ' . MARKER . "\n\n" . MARKER . "\n\n[link](javascript:window.__xss=1)", 'seed', $now);
}

app(Invitations::class)->create(5, null, SHORT, 'seed', $now);
app(BugReports::class)->submit($marker, '/game.php?page=overview', MARKER, MARKER, $now);
app(ErrorMonitor::class)->record(new RuntimeException(MARKER), $now);

// an operator who signs in with a code from the known secret
$operator = new Operator(['email' => 'ops@example.com', 'password' => PASSWORD . PASSWORD, 'created_at' => $now]);
$totp = new Totp();
app(TwoFactor::class)->enable($operator, OPERATOR_SECRET, $totp->code(OPERATOR_SECRET, $totp->step($now - 60)), $now - 60);

file_put_contents(__DIR__ . '/.seed.json', json_encode([
    'admin' => ['email' => 'admin@example.com', 'password' => PASSWORD],
    'marker' => $marker->id,
    'alliance' => $alliance,
    'galaxy' => (int) $home->planet_galaxy,
    'system' => (int) $home->planet_system,
    'error' => (int) DB::connection('central')->table('error_groups')->sole(['id'])->id,
    'operator' => ['email' => 'ops@example.com', 'password' => PASSWORD . PASSWORD, 'secret' => OPERATOR_SECRET],
], JSON_PRETTY_PRINT));

echo "Seeded: player {$marker->id}, alliance {$alliance}, operator ops@example.com.\n";
