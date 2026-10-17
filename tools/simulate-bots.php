<?php

/**
 * Bot simulator: lets N bot players play against each other for many
 * simulated hours on a real MariaDB/MySQL database, running the real game
 * tick (App\Libraries\UpdatesLibrary). It doubles as a long-running
 * integration test: every throwable, every log entry (report() included) and
 * a set of daily invariant checks end up in a Markdown + JSON report.
 *
 * Usage (from the project root):
 *
 *   php -d memory_limit=2G tools/simulate-bots.php --db=xgp_sim \
 *       --bots=10 --hours=1440 --step=600 --seed=42 \
 *       --report=storage/simulations/full.md
 *
 * Options:
 *   --db=NAME          target database, REQUIRED; must end with _sim or _sim2
 *                      because it is WIPED and installed from scratch
 *   --bots=N           bots to create (default 10)
 *   --hours=H          simulated hours (default 72)
 *   --step=S           simulated seconds per tick (default 600)
 *   --seed=N           seed for mt_rand/rand/shuffle/array_rand, random_int
 *                      inside the game namespaces and Str::random (default:
 *                      random, printed so a run can be repeated)
 *   --start=TS         unix time the simulated clock starts at (default: now)
 *   --galaxy-from=G    first galaxy bots are placed in (default 1)
 *   --galaxy-to=G      last galaxy bots are placed in (default 1)
 *   --option=K=V       overwrite a game option (table options) after the
 *                      install, repeatable (e.g. --option=game_speed=25000)
 *   --grant=H:SHIP:N[:B]
 *                      scenario: at simulated hour H every bot (or the first
 *                      B bots) gets N ships of id SHIP on its home planet, as
 *                      an admin could give them (e.g. --grant=480:214:3:3 for
 *                      three death stars for three bots, which bots cannot
 *                      reach at speed 1 in a few weeks), repeatable
 *   --moon-unit=N      test acceleration: 1 % moon chance per N debris instead
 *                      of the game's 100,000 (still at most 20 %), so moon
 *                      creation and destruction happen in a short run
 *   --report=PATH      Markdown report (default storage/simulations/
 *                      sim-<db>-<date>.md); the JSON and the game log go next
 *                      to it (.json, .log)
 *   --self-test        check the harness itself: right after the install it
 *                      plants known faults (negative metal and ships, a
 *                      malformed hangar queue, a stuck fleet, a lowered
 *                      building level, an idle bot, a report()ed exception,
 *                      a tick that throws)
 *                      and fails (exit 3) unless each one is reported
 *   --profile          run the tick's phases one by one (through reflection)
 *                      and time each of them; otherwise the tick runs exactly
 *                      as in production (new UpdatesLibrary())
 *   --db-host, --db-port, --db-user, --db-pass
 *                      connection (default localhost, 3306, xgp, xgp)
 *
 * How time is faked: the game calls time() and date() unqualified inside its
 * namespaces, and PHP resolves such a call in the current namespace first.
 * Before Laravel boots, this script defines time(), date() and strtotime()
 * (and, for reproducibility, random_int()) in every namespace found under
 * app/ and database/, returning the simulated clock; Carbon gets the same
 * moment through setTestNow(). Nothing in the database is shifted.
 *
 * The game lets at most 10 bots act per tick and each bot waits 10 to 20
 * minutes: with more than 10 bots, use a smaller --step, or the check
 * "bot_idle_over_an_hour" reports starving bots.
 *
 * Ctrl+C stops after the current tick and still writes the report; the
 * report is also rewritten after every simulated day.
 */

declare(strict_types=1);

namespace XGProyect\Tools\Simulator;

use App\Libraries\UpdatesLibrary;
use App\Models\Planets;
use App\Models\User;
use App\Core\Enumerators\UserRanksEnumerator;
use App\Libraries\PlanetLib;
use App\Services\Admin\BotService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;
use Throwable;

const MISSIONS = [
    1 => 'attack', 2 => 'acs', 3 => 'transport', 4 => 'deploy', 5 => 'hold',
    6 => 'espionage', 7 => 'colonize', 8 => 'recycle', 9 => 'destroy',
    10 => 'missile', 15 => 'expedition',
];

/**
 * The simulated clock every shimmed time() reads.
 */
final class SimClock
{
    private static int $now = 0;

    public static function now(): int
    {
        return self::$now;
    }

    /** only the clock, before Carbon is loadable */
    public static function init(int $now): void
    {
        self::$now = $now;
    }

    public static function set(int $now): void
    {
        self::$now = $now;
        Carbon::setTestNow(Carbon::createFromTimestamp($now));
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp($now));
    }
}

/**
 * Defines the clock (and seeded random_int) functions in the game namespaces.
 */
final class ClockShim
{
    /**
     * @param list<string> $dirs
     *
     * @return list<string> the namespaces that got the shim
     */
    public static function install(array $dirs): array
    {
        $namespaces = [];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (preg_match_all('/^\s*namespace\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*[;{]/m', $source, $matches) > 0) {
                    foreach ($matches[1] as $namespace) {
                        $namespaces[$namespace] = true;
                    }
                }
            }
        }

        $clock = '\\' . SimClock::class;
        $installed = [];

        foreach (array_keys($namespaces) as $namespace) {
            if (function_exists($namespace . '\\time')) {
                continue;
            }

            eval(
                'namespace ' . $namespace . ';' .
                'function time(): int { return ' . $clock . '::now(); }' .
                'function date(string $format, ?int $timestamp = null): string { return \date($format, $timestamp ?? ' . $clock . '::now()); }' .
                'function strtotime(string $datetime, ?int $baseTimestamp = null): int|false { return \strtotime($datetime, $baseTimestamp ?? ' . $clock . '::now()); }' .
                'function random_int(int $min, int $max): int { return ($max - $min) <= \mt_getrandmax() ? \mt_rand($min, $max) : \random_int($min, $max); }'
            );
            $installed[] = $namespace;
        }

        sort($installed);

        return $installed;
    }
}

/**
 * Command line options.
 */
final class Options
{
    /**
     * @param array<string, string>        $gameOptions
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $grants hour, ship id, amount, bots (0: all)
     */
    public function __construct(
        public readonly string $database,
        public readonly int $bots,
        public readonly int $hours,
        public readonly int $step,
        public readonly int $seed,
        public readonly int $start,
        public readonly int $galaxyFrom,
        public readonly int $galaxyTo,
        public readonly array $gameOptions,
        public readonly string $report,
        public readonly bool $profile,
        public readonly bool $selfTest,
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbUser,
        public readonly string $dbPass,
        public readonly array $grants = [],
        public readonly int $moonUnit = 0,
    ) {
    }

    public static function fromArgv(string $root): self
    {
        $opts = getopt('', [
            'db:', 'bots:', 'hours:', 'step:', 'seed:', 'start:', 'galaxy-from:', 'galaxy-to:',
            'option:', 'report:', 'profile', 'self-test', 'help', 'db-host:', 'db-port:', 'db-user:', 'db-pass:', 'grant:', 'moon-unit:',
        ]);

        if ($opts === false || isset($opts['help'])) {
            self::usage();
        }

        $database = self::str($opts, 'db', '');

        if (preg_match('/^[A-Za-z0-9_]+_sim2?$/', $database) !== 1) {
            fwrite(STDERR, "Refusing to run: give the target database with --db=NAME; it must end with _sim or _sim2 because it is wiped.\n");
            exit(2);
        }

        $gameOptions = [];
        $raw = $opts['option'] ?? [];

        foreach (is_array($raw) ? $raw : [$raw] as $pair) {
            if (!is_string($pair) || !str_contains($pair, '=')) {
                fwrite(STDERR, "--option expects NAME=VALUE\n");
                exit(2);
            }

            [$name, $value] = explode('=', $pair, 2);
            $gameOptions[$name] = $value;
        }

        $grants = [];
        $rawGrants = $opts['grant'] ?? [];

        foreach (is_array($rawGrants) ? $rawGrants : [$rawGrants] as $grant) {
            if (!is_string($grant) || preg_match('/^(\d+):(\d+):(\d+)(?::(\d+))?$/', $grant, $parts) !== 1) {
                fwrite(STDERR, "--grant expects HOUR:SHIP_ID:AMOUNT[:BOTS]\n");
                exit(2);
            }

            $grants[] = [(int) $parts[1], (int) $parts[2], (int) $parts[3], (int) ($parts[4] ?? 0)];
        }

        $seedGiven = self::str($opts, 'seed', '');
        $seed = $seedGiven !== '' ? (int) $seedGiven : random_int(1, 2_000_000_000);
        $report = self::str($opts, 'report', $root . '/storage/simulations/sim-' . $database . '-' . date('Ymd-His') . '.md');

        if (!str_starts_with($report, '/')) {
            $report = getcwd() . '/' . $report;
        }

        $options = new self(
            $database,
            self::int($opts, 'bots', 10),
            self::int($opts, 'hours', 72),
            self::int($opts, 'step', 600),
            $seed,
            self::int($opts, 'start', time()),
            self::int($opts, 'galaxy-from', 1),
            self::int($opts, 'galaxy-to', 1),
            $gameOptions,
            $report,
            isset($opts['profile']),
            isset($opts['self-test']),
            self::str($opts, 'db-host', 'localhost'),
            self::int($opts, 'db-port', 3306),
            self::str($opts, 'db-user', 'xgp'),
            self::str($opts, 'db-pass', 'xgp'),
            $grants,
            self::int($opts, 'moon-unit', 0),
        );

        if ($options->bots < 1 || $options->hours < 1 || $options->step < 1 || $options->galaxyFrom < 1 || $options->galaxyTo < $options->galaxyFrom) {
            fwrite(STDERR, "--bots, --hours and --step must be positive and the galaxy range valid.\n");
            exit(2);
        }

        return $options;
    }

    private static function usage(): never
    {
        $doc = (string) file_get_contents(__FILE__);
        preg_match('#/\*\*(.*?)\*/#s', $doc, $match);
        fwrite(STDOUT, preg_replace('/^ ?\* ?/m', '', $match[1] ?? '') . "\n");
        exit(0);
    }

    /** @param array<string, mixed> $opts */
    private static function str(array $opts, string $key, string $default): string
    {
        $value = $opts[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** @param array<string, mixed> $opts */
    private static function int(array $opts, string $key, int $default): int
    {
        $value = $opts[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_string($value) || !is_numeric($value)) {
            fwrite(STDERR, "--{$key} expects a number\n");
            exit(2);
        }

        return (int) $value;
    }
}

/**
 * Errors (thrown out of the tick or logged by the game) and invariant
 * violations, grouped.
 */
final class Recorder
{
    /** @var array<string, array{source: string, class: string, message: string, location: string, count: int, first: int, last: int, trace: list<string>}> */
    public array $errors = [];

    /** @var array<string, array{count: int, first: int, last: int, examples: list<array<string, mixed>>}> */
    public array $violations = [];

    public int $errorCount = 0;

    public int $violationCount = 0;

    public function __construct(private readonly int $start, private readonly string $root)
    {
    }

    public function throwable(Throwable $e, string $source): void
    {
        $location = $this->relative($e->getFile()) . ':' . $e->getLine();
        $message = $e->getMessage();
        $key = $source . '|' . $e::class . '|' . self::normalize($message) . '|' . $location;
        $this->add($key, $source, $e::class, $message, $location, $this->trace($e));

        if ($e->getPrevious() !== null) {
            $this->throwable($e->getPrevious(), $source . ' (previous)');
        }
    }

    public function logged(string $level, string $message): void
    {
        $key = 'log:' . $level . '|' . self::normalize($message);
        $this->add($key, 'log:' . $level, '-', $message, '-', []);
    }

    /**
     * @param array<string, mixed> $details
     */
    public function violation(string $type, array $details): void
    {
        $now = SimClock::now();
        $this->violationCount++;
        $entry = $this->violations[$type] ?? ['count' => 0, 'first' => $now, 'last' => $now, 'examples' => []];
        $entry['count']++;
        $entry['last'] = $now;

        if (count($entry['examples']) < 8) {
            $entry['examples'][] = ['sim_hour' => round(($now - $this->start) / 3600, 2)] + $details;
        }

        $this->violations[$type] = $entry;
    }

    public function simHour(int $time): float
    {
        return round(($time - $this->start) / 3600, 2);
    }

    /**
     * @param list<string> $trace
     */
    private function add(string $key, string $source, string $class, string $message, string $location, array $trace): void
    {
        $now = SimClock::now();
        $this->errorCount++;

        if (!isset($this->errors[$key])) {
            $this->errors[$key] = [
                'source' => $source, 'class' => $class, 'message' => mb_substr($message, 0, 2000), 'location' => $location,
                'count' => 0, 'first' => $now, 'last' => $now, 'trace' => $trace,
            ];
        }

        $this->errors[$key]['count']++;
        $this->errors[$key]['last'] = $now;
    }

    /**
     * @return list<string>
     */
    private function trace(Throwable $e): array
    {
        $lines = [];

        foreach (array_slice($e->getTrace(), 0, 12) as $frame) {
            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()';
            $where = isset($frame['file']) ? $this->relative($frame['file']) . ':' . ($frame['line'] ?? 0) : '[internal]';
            $lines[] = $where . ' ' . $call;
        }

        return $lines;
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, $this->root . '/') ? substr($path, strlen($this->root) + 1) : $path;
    }

    private static function normalize(string $message): string
    {
        return (string) preg_replace(['/\d+(\.\d+)?/', "/'[^']*'/"], ['N', "'…'"], mb_substr($message, 0, 300));
    }
}

/**
 * Follows the world tick by tick: fleets (launch, mission, outcome), battle
 * reports, messages (expedition results, espionage), new planets and moons.
 */
final class WorldTracker
{
    /** @var array<int, array{owner: int, mission: int, launched: int, start: int, end: int, amount: int, last_amount: int, last_mess: int, ended: ?int, outcome: ?string}> */
    public array $fleets = [];

    /** @var array<string, true> */
    private array $reportIds = [];

    private int $reportCount = 0;

    /** @var list<array{time: int, owners: string, destroyed: int}> */
    public array $battles = [];

    private int $lastMessageId = 0;

    /** @var array<int, array<string, int>> message counts by receiver and kind */
    public array $messages = [];

    /** @var array<int, array<string, int>> expedition outcome counts by owner */
    public array $expeditions = [];

    private int $lastPlanetId = 0;

    /** @var list<array{time: int, owner: int, type: int, coords: string}> */
    public array $newPlanets = [];

    /** @var array<string, string> expedition message prefix => outcome */
    private array $expeditionTexts = [];

    private string $expeditionTitle = '';

    /** @var array<int, array<int, true>> ACS groups seen: group => owners */
    public array $groups = [];

    /** missile result messages (attacker and target each get one) and the defences they list as destroyed */
    public int $missileMessages = 0;

    public int $missileDestroyed = 0;

    public function __construct()
    {
        $texts = trans('game/expedition');

        if (is_array($texts)) {
            $title = $texts['exp_report_title'] ?? '';
            $this->expeditionTitle = is_string($title) ? self::prefix($title) : '';

            foreach ($texts as $key => $text) {
                if (!is_string($key) || !is_string($text)) {
                    continue;
                }

                $outcome = self::outcomeOf($key);

                if ($outcome !== null && self::prefix($text) !== '') {
                    $this->expeditionTexts[self::prefix($text)] = $outcome;
                }
            }
        }
    }

    public function baseline(): void
    {
        $this->lastPlanetId = (int) DB::table('planets')->max('planet_id');
        $this->lastMessageId = (int) DB::table('messages')->max('message_id');
    }

    public function afterTick(int $now): void
    {
        $this->trackFleets($now);
        $this->trackReports();
        $this->trackMessages();
        $this->trackPlanets($now);
    }

    /**
     * @return array<string, int> fleets in flight by mission name
     */
    public function inFlight(): array
    {
        $flying = [];

        foreach ($this->fleets as $fleet) {
            if ($fleet['ended'] === null) {
                $name = MISSIONS[$fleet['mission']] ?? 'mission ' . $fleet['mission'];
                $flying[$name] = ($flying[$name] ?? 0) + 1;
            }
        }

        ksort($flying);

        return $flying;
    }

    private function trackFleets(int $now): void
    {
        $seen = [];

        foreach (DB::table('fleets')->get(['fleet_id', 'fleet_owner', 'fleet_mission', 'fleet_mess', 'fleet_amount', 'fleet_start_time', 'fleet_end_time', 'fleet_group']) as $row) {
            $id = (int) $row->fleet_id;
            $seen[$id] = true;

            if ((int) $row->fleet_group !== 0) {
                $this->groups[(int) $row->fleet_group][(int) $row->fleet_owner] = true;
            }

            if (!isset($this->fleets[$id])) {
                $this->fleets[$id] = [
                    'owner' => (int) $row->fleet_owner, 'mission' => (int) $row->fleet_mission, 'launched' => $now,
                    'start' => (int) $row->fleet_start_time, 'end' => (int) $row->fleet_end_time,
                    'amount' => (int) $row->fleet_amount, 'last_amount' => (int) $row->fleet_amount,
                    'last_mess' => (int) $row->fleet_mess, 'ended' => null, 'outcome' => null,
                ];

                continue;
            }

            $this->fleets[$id]['last_amount'] = (int) $row->fleet_amount;
            $this->fleets[$id]['last_mess'] = (int) $row->fleet_mess;
            $this->fleets[$id]['end'] = (int) $row->fleet_end_time;
        }

        foreach ($this->fleets as $id => $fleet) {
            if ($fleet['ended'] !== null || isset($seen[$id])) {
                continue;
            }

            $outcome = $now >= $fleet['end'] ? 'returned' : 'ended at target';

            if ($fleet['last_amount'] < $fleet['amount']) {
                $outcome .= ', lost ships';
            }

            $this->fleets[$id]['ended'] = $now;
            $this->fleets[$id]['outcome'] = $outcome;
        }
    }

    private function trackReports(): void
    {
        $count = (int) DB::table('reports')->count();

        if ($count === $this->reportCount) {
            return;
        }

        $this->reportCount = $count;

        foreach (DB::table('reports')->get(['report_rid', 'report_owners', 'report_destroyed', 'report_time']) as $row) {
            $rid = (string) $row->report_rid;

            if (!isset($this->reportIds[$rid])) {
                $this->reportIds[$rid] = true;
                $this->battles[] = ['time' => (int) $row->report_time, 'owners' => (string) $row->report_owners, 'destroyed' => (int) $row->report_destroyed];
            }
        }
    }

    private function trackMessages(): void
    {
        $rows = DB::table('messages')->where('message_id', '>', $this->lastMessageId)->orderBy('message_id')
            ->get(['message_id', 'message_receiver', 'message_type', 'message_subject', 'message_text']);

        foreach ($rows as $row) {
            $this->lastMessageId = max($this->lastMessageId, (int) $row->message_id);
            $receiver = (int) $row->message_receiver;
            $kind = match ((int) $row->message_type) {
                0 => 'espionage', 1 => 'combat', 2 => 'expedition', 3 => 'alliance', 4 => 'user', default => 'general',
            };
            $this->messages[$receiver][$kind] = ($this->messages[$receiver][$kind] ?? 0) + 1;

            $subject = (string) $row->message_subject;

            if ($subject === __('game/missile.mis_attack')) {
                $this->missileMessages++;
                preg_match_all('/\(-([\d.,]+)\)/', (string) $row->message_text, $losses);
                $this->missileDestroyed += (int) array_sum(array_map(static fn (string $n): int => (int) preg_replace('/\D/', '', $n), $losses[1]));
            }

            if ($this->expeditionTitle !== '' && str_starts_with($subject, $this->expeditionTitle)) {
                $outcome = $this->classifyExpedition((string) $row->message_text);
                $this->expeditions[$receiver][$outcome] = ($this->expeditions[$receiver][$outcome] ?? 0) + 1;
            }
        }
    }

    private function trackPlanets(int $now): void
    {
        $rows = DB::table('planets')->where('planet_id', '>', $this->lastPlanetId)->orderBy('planet_id')
            ->get(['planet_id', 'planet_user_id', 'planet_type', 'planet_galaxy', 'planet_system', 'planet_planet']);

        foreach ($rows as $row) {
            $this->lastPlanetId = max($this->lastPlanetId, (int) $row->planet_id);
            $this->newPlanets[] = [
                'time' => $now, 'owner' => (int) $row->planet_user_id, 'type' => (int) $row->planet_type,
                'coords' => $row->planet_galaxy . ':' . $row->planet_system . ':' . $row->planet_planet,
            ];
        }
    }

    private function classifyExpedition(string $text): string
    {
        $best = '';
        $outcome = 'unclassified';

        foreach ($this->expeditionTexts as $prefix => $kind) {
            if (strlen($prefix) > strlen($best) && str_starts_with($text, $prefix)) {
                $best = $prefix;
                $outcome = $kind;
            }
        }

        return $outcome;
    }

    private static function outcomeOf(string $key): ?string
    {
        $map = [
            'exp_new_ships_' => 'ships', 'exp_new_resources_' => 'resources', 'exp_primitive_enemy_' => 'pirates',
            'exp_advanced_enemy_' => 'aliens', 'exp_trader' => 'merchant', 'exp_dm_' => 'dark matter',
            'exp_delay_' => 'delay', 'exp_faster_' => 'early return', 'exp_found_object_' => 'item',
            'exp_nothing_' => 'nothing', 'exp_lost_' => 'black hole',
        ];

        foreach ($map as $prefix => $outcome) {
            if (str_starts_with($key, $prefix)) {
                return $outcome;
            }
        }

        return null;
    }

    /** the literal start of a translated text, up to the first placeholder */
    private static function prefix(string $text): string
    {
        $cut = preg_split('/%[sdf0-9$]|:[a-z_]+/', $text, 2);

        return mb_substr($cut[0] ?? '', 0, 60);
    }
}

/**
 * The daily invariant checks.
 */
final class Invariants
{
    /** @var list<string> */
    private array $shipColumns;

    /** @var list<string> */
    private array $defenseColumns;

    /** @var list<string> */
    private array $researchColumns;

    /** @var list<string> */
    private array $buildingColumns;

    /** @var array<int, array<string, int>> */
    private array $research = [];

    /** @var array<int, array<string, int>> */
    private array $buildings = [];

    public function __construct(private readonly Recorder $recorder, private readonly int $step)
    {
        $this->shipColumns = self::levelColumns('ships', 'ship_');
        $this->defenseColumns = self::levelColumns('defenses', 'defense_');
        $this->researchColumns = self::levelColumns('research', 'research_');
        $this->buildingColumns = self::levelColumns('buildings', 'building_');
    }

    public function check(int $now): void
    {
        $this->resources();
        $this->counts('ships', 'ship_planet_id', $this->shipColumns, 'negative_ship_count');
        $this->counts('defenses', 'defense_planet_id', $this->defenseColumns, 'negative_defence_count');
        $this->fields();
        $this->fleets($now);
        $this->queues();
        $this->hangars();
        $this->levels();
        $this->botsActive($now);
        $this->misc();
    }

    private function resources(): void
    {
        $rows = DB::table('planets')->where('planet_destroyed', 0)
            ->where(fn ($q) => $q->where('planet_metal', '<', 0)->orWhere('planet_crystal', '<', 0)->orWhere('planet_deuterium', '<', 0)
                ->orWhere('planet_debris_metal', '<', 0)->orWhere('planet_debris_crystal', '<', 0))
            ->get(['planet_id', 'planet_user_id', 'planet_type', 'planet_metal', 'planet_crystal', 'planet_deuterium', 'planet_debris_metal', 'planet_debris_crystal']);

        foreach ($rows as $row) {
            $this->recorder->violation('negative_resources_or_debris', (array) $row);
        }
    }

    /**
     * @param list<string> $columns
     */
    private function counts(string $table, string $key, array $columns, string $type): void
    {
        if ($columns === []) {
            return;
        }

        $query = DB::table($table);

        foreach ($columns as $column) {
            $query->orWhere($column, '<', 0);
        }

        foreach ($query->get() as $row) {
            $negative = array_filter((array) $row, fn ($value, $column) => in_array($column, $columns, true) && $value < 0, ARRAY_FILTER_USE_BOTH);
            $this->recorder->violation($type, ['planet_id' => $row->$key] + $negative);
        }
    }

    private function fields(): void
    {
        $occupied = implode(' + ', array_map(
            fn (string $c) => 'b.`' . $c . '`',
            array_values(array_filter($this->buildingColumns, fn (string $c) => $c !== 'building_space_dock'))
        ));
        $rows = DB::select(
            'SELECT p.planet_id, p.planet_user_id, p.planet_type, p.planet_field_current, p.planet_field_max,
                b.building_terraformer, (' . $occupied . ') AS occupied
            FROM `' . DB::getTablePrefix() . 'planets` p
            INNER JOIN `' . DB::getTablePrefix() . 'buildings` b ON b.building_planet_id = p.planet_id
            WHERE p.planet_destroyed = 0'
        );

        foreach ($rows as $row) {
            $max = (int) $row->planet_field_max + (int) $row->building_terraformer * (defined('FIELDS_BY_TERRAFORMER') ? (int) FIELDS_BY_TERRAFORMER : 5);

            if ((int) $row->planet_field_current > $max) {
                $this->recorder->violation('fields_over_max', (array) $row + ['max_with_terraformer' => $max]);
            }

            if ((int) $row->planet_field_current !== (int) $row->occupied) {
                $this->recorder->violation('field_counter_differs_from_building_levels', (array) $row);
            }
        }
    }

    private function fleets(int $now): void
    {
        $limit = $now - 2 * $this->step;
        $rows = DB::table('fleets')
            ->where(fn ($q) => $q->where('fleet_end_time', '<', $limit)->orWhere(fn ($q) => $q->where('fleet_mess', 0)->where('fleet_start_time', '<', $limit)->where('fleet_end_stay', '<', $limit)))
            ->get(['fleet_id', 'fleet_owner', 'fleet_mission', 'fleet_mess', 'fleet_start_time', 'fleet_end_stay', 'fleet_end_time']);

        foreach ($rows as $row) {
            $this->recorder->violation('stuck_fleet', (array) $row + ['overdue_s' => $now - (int) $row->fleet_end_time]);
        }

        $rows = DB::table('fleets as f')->leftJoin('users as u', 'u.id', '=', 'f.fleet_owner')
            ->where(fn ($q) => $q->whereNull('u.id')->orWhere('f.fleet_amount', '<=', 0)->orWhere('f.fleet_resource_metal', '<', 0)
                ->orWhere('f.fleet_resource_crystal', '<', 0)->orWhere('f.fleet_resource_deuterium', '<', 0)->orWhere('f.fleet_array', '=', ''))
            ->get(['f.fleet_id', 'f.fleet_owner', 'f.fleet_mission', 'f.fleet_amount', 'f.fleet_array', 'f.fleet_resource_metal', 'f.fleet_resource_crystal', 'f.fleet_resource_deuterium']);

        foreach ($rows as $row) {
            $this->recorder->violation('fleet_malformed', (array) $row);
        }
    }

    /**
     * A queue entry that finished before the owner's last turn should be gone:
     * the turn brings the queues up to date.
     */
    private function queues(): void
    {
        $prefix = DB::getTablePrefix();
        $rows = DB::select(
            'SELECT q.planet_id, q.building_id, q.target_level, q.end_time, u.id AS user_id, u.onlinetime
            FROM `' . $prefix . 'building_queues` q
            INNER JOIN `' . $prefix . 'planets` p ON p.planet_id = q.planet_id
            INNER JOIN `' . $prefix . 'users` u ON u.id = p.planet_user_id
            WHERE u.user_is_bot = 1 AND p.planet_type = 1 AND p.planet_destroyed = 0 AND q.end_time < u.onlinetime'
        );

        foreach ($rows as $row) {
            $this->recorder->violation('stuck_building_queue', (array) $row + ['overdue_s' => (int) $row->onlinetime - (int) $row->end_time]);
        }

        $rows = DB::select(
            'SELECT q.user_id, q.planet_id, q.tech_id, q.target_level, q.end_time, u.onlinetime
            FROM `' . $prefix . 'research_queues` q
            INNER JOIN `' . $prefix . 'users` u ON u.id = q.user_id
            WHERE u.user_is_bot = 1 AND q.end_time < u.onlinetime'
        );

        foreach ($rows as $row) {
            $this->recorder->violation('stuck_research_queue', (array) $row + ['overdue_s' => (int) $row->onlinetime - (int) $row->end_time]);
        }
    }

    private function hangars(): void
    {
        $rows = DB::table('planets')->where('planet_destroyed', 0)
            ->whereNotNull('planet_b_hangar_id')->whereNotIn('planet_b_hangar_id', ['', '0'])
            ->get(['planet_id', 'planet_user_id', 'planet_b_hangar_id', 'planet_b_hangar']);

        foreach ($rows as $row) {
            $queue = (string) $row->planet_b_hangar_id;
            $wellFormed = preg_match('/^(\d+,\d+;)+$/', $queue) === 1;

            if ($wellFormed) {
                foreach (array_filter(explode(';', $queue)) as $entry) {
                    [$id, $count] = array_map('intval', explode(',', $entry));
                    $wellFormed = $wellFormed && $count > 0 && (($id >= 202 && $id <= 299) || ($id >= 401 && $id <= 599));
                }
            }

            if (!$wellFormed) {
                $this->recorder->violation('hangar_queue_malformed', (array) $row);
            }
        }
    }

    /**
     * Research and building levels never go down (bots do not demolish;
     * a destroyed or deleted planet is skipped).
     */
    private function levels(): void
    {
        $research = [];

        foreach (DB::table('research')->get() as $row) {
            $levels = [];

            foreach ($this->researchColumns as $column) {
                $levels[$column] = (int) $row->$column;
            }

            $research[(int) $row->research_user_id] = $levels;
        }

        $buildings = [];
        $rows = DB::table('buildings as b')->join('planets as p', 'p.planet_id', '=', 'b.building_planet_id')
            ->where('p.planet_destroyed', 0)->get(array_merge(['b.building_planet_id', 'p.planet_user_id'], array_map(fn ($c) => 'b.' . $c, $this->buildingColumns)));

        foreach ($rows as $row) {
            $levels = [];

            foreach ($this->buildingColumns as $column) {
                $levels[$column] = (int) $row->$column;
            }

            $buildings[(int) $row->building_planet_id] = $levels;
        }

        $this->compare($this->research, $research, 'research_level_decreased', 'user_id');
        $this->compare($this->buildings, $buildings, 'building_level_decreased', 'planet_id');
        $this->research = $research;
        $this->buildings = $buildings;
    }

    /**
     * @param array<int, array<string, int>> $before
     * @param array<int, array<string, int>> $after
     */
    private function compare(array $before, array $after, string $type, string $key): void
    {
        foreach ($before as $id => $levels) {
            if (!isset($after[$id])) {
                continue;
            }

            foreach ($levels as $column => $level) {
                if (($after[$id][$column] ?? 0) < $level) {
                    $this->recorder->violation($type, [$key => $id, 'column' => $column, 'before' => $level, 'after' => $after[$id][$column] ?? 0]);
                }
            }
        }
    }

    private function botsActive(int $now): void
    {
        $rows = DB::table('users')->where('user_is_bot', 1)->where('onlinetime', '<', $now - 3600)
            ->get(['id', 'name', 'onlinetime', 'user_bot_next_action']);

        foreach ($rows as $row) {
            $this->recorder->violation('bot_idle_over_an_hour', (array) $row + ['idle_s' => $now - (int) $row->onlinetime]);
        }

        $prefix = DB::getTablePrefix();
        $rows = DB::select(
            'SELECT p.planet_id, u.id AS user_id, p.planet_last_update, u.onlinetime
            FROM `' . $prefix . 'planets` p
            INNER JOIN `' . $prefix . 'users` u ON u.id = p.planet_user_id
            WHERE u.user_is_bot = 1 AND p.planet_type = 1 AND p.planet_destroyed = 0 AND p.planet_last_update < u.onlinetime'
        );

        foreach ($rows as $row) {
            $this->recorder->violation('bot_planet_not_updated_on_turn', (array) $row);
        }
    }

    private function misc(): void
    {
        $rows = DB::table('planets')->where('planet_destroyed', 0)
            ->groupBy('planet_galaxy', 'planet_system', 'planet_planet', 'planet_type')->havingRaw('COUNT(*) > 1')
            ->get(['planet_galaxy', 'planet_system', 'planet_planet', 'planet_type', DB::raw('COUNT(*) AS planets')]);

        foreach ($rows as $row) {
            $this->recorder->violation('duplicate_planet_coordinates', (array) $row);
        }

        $rows = DB::table('users_statistics')->where(fn ($q) => $q->where('user_statistic_total_points', '<', 0)
            ->orWhere('user_statistic_buildings_points', '<', 0)->orWhere('user_statistic_ships_points', '<', 0)
            ->orWhere('user_statistic_defenses_points', '<', 0)->orWhere('user_statistic_technology_points', '<', 0))->get();

        foreach ($rows as $row) {
            $this->recorder->violation('negative_points', (array) $row);
        }

        foreach (DB::table('premium')->where('premium_dark_matter', '<', 0)->get(['premium_user_id', 'premium_dark_matter']) as $row) {
            $this->recorder->violation('negative_dark_matter', (array) $row);
        }

        $rows = DB::table('planets as m')->leftJoin('planets as p', fn ($j) => $j->on('p.planet_galaxy', '=', 'm.planet_galaxy')
            ->on('p.planet_system', '=', 'm.planet_system')->on('p.planet_planet', '=', 'm.planet_planet')
            ->where('p.planet_type', 1)->where('p.planet_destroyed', 0))
            ->where('m.planet_type', 3)->where('m.planet_destroyed', 0)->whereNull('p.planet_id')->get(['m.planet_id', 'm.planet_user_id']);

        foreach ($rows as $row) {
            $this->recorder->violation('moon_without_planet', (array) $row);
        }
    }

    /**
     * @return list<string>
     */
    private static function levelColumns(string $table, string $prefix): array
    {
        return array_values(array_filter(
            Schema::getColumnListing($table),
            fn (string $column) => str_starts_with($column, $prefix) && !in_array($column, [$prefix . 'id', $prefix . 'user_id', $prefix . 'planet_id', $prefix . 'current_research'], true)
        ));
    }
}

/**
 * Wall time of the ticks and of the SQL they run.
 */
final class Profiler
{
    /** @var list<float> tick wall times in ms */
    public array $ticks = [];

    /** @var array<string, array{count: int, ms: float}> */
    public array $queries = [];

    /** @var array<string, float> ms per tick phase (profile mode) */
    public array $phases = [];

    public int $queryCount = 0;

    public float $queryMs = 0.0;

    /** @var array<string, int> trader actions, told by their writes (the merchant keeps no record) */
    public array $trades = [];

    public function listen(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            $this->countTrade($query);
            $this->queryCount++;
            $this->queryMs += $query->time;
            $key = mb_substr((string) preg_replace(['/\s+/', "/'(?:[^'\\\\]|\\\\.)*'/", '/\b\d+(\.\d+)?\b/', '/(\?|N)(, ?(\?|N))+/'], [' ', "'…'", 'N', '…'], $query->sql), 0, 220);
            $entry = $this->queries[$key] ?? ['count' => 0, 'ms' => 0.0];
            $entry['count']++;
            $entry['ms'] += $query->time;
            $this->queries[$key] = $entry;
        });
    }

    private function countTrade(QueryExecuted $query): void
    {
        $sql = $query->sql;
        $offer = str_starts_with($sql, 'update `xgp_premium`') && str_contains($sql, 'premium_merchant_offer');
        $kind = match (true) {
            $offer && str_contains($sql, 'premium_dark_matter - ') => 'merchant_calls',
            $offer => ($query->bindings[0] ?? null) === null ? 'merchant_exchanges' : 'merchant_expedition_offers',
            str_starts_with($sql, 'insert into `xgp_auction_bids`') => 'auction_bids',
            str_starts_with($sql, 'update `xgp_auction_bids`') => 'auction_raises',
            str_contains($sql, '`xgp_import_exports` set `bought`') => 'containers_bought',
            str_contains($sql, '`xgp_user_items` set `amount` = `amount` -') => 'items_used',
            default => null,
        };

        if ($kind !== null) {
            $this->trades[$kind] = ($this->trades[$kind] ?? 0) + 1;
        }
    }

    public function phase(string $name, callable $run): void
    {
        $started = hrtime(true);

        try {
            $run();
        } finally {
            $this->phases[$name] = ($this->phases[$name] ?? 0.0) + (hrtime(true) - $started) / 1e6;
        }
    }

    /**
     * @return array<string, float|int>
     */
    public function tickStats(): array
    {
        $ticks = $this->ticks;
        sort($ticks);
        $n = count($ticks);

        if ($n === 0) {
            return [];
        }

        return [
            'ticks' => $n,
            'avg_ms' => round(array_sum($ticks) / $n, 2),
            'p50_ms' => round($ticks[intdiv($n, 2)], 2),
            'p95_ms' => round($ticks[min($n - 1, (int) floor($n * 0.95))], 2),
            'max_ms' => round($ticks[$n - 1], 2),
            'total_s' => round(array_sum($ticks) / 1000, 1),
            'queries' => $this->queryCount,
            'queries_per_tick' => round($this->queryCount / $n, 1),
            'query_s' => round($this->queryMs / 1000, 1),
        ];
    }
}

/**
 * Runs the simulation.
 */
final class Simulator
{
    private Recorder $recorder;

    private WorldTracker $tracker;

    private Invariants $invariants;

    private Profiler $profiler;

    private float $wallStart;

    private int $lastTick = 0;

    private bool $stop = false;

    /** @var list<string> */
    private array $progress = [];

    /** @var list<int> */
    private array $botIds = [];

    private int $adminId = 0;

    /** @var array<int, bool> grants already given, by their index */
    private array $granted = [];

    /** @var list<string> */
    private array $shimmed;

    /**
     * @param list<string> $shimmed
     */
    public function __construct(private readonly Options $options, private readonly string $root, array $shimmed)
    {
        $this->shimmed = $shimmed;
        $this->wallStart = microtime(true);
        $this->recorder = new Recorder($options->start, $root);
        $this->profiler = new Profiler();
    }

    public function run(): int
    {
        $this->bootLaravel();
        $this->install();
        $this->tracker = new WorldTracker();
        $this->tracker->baseline();
        $this->invariants = new Invariants($this->recorder, $this->options->step);
        $this->invariants->check(SimClock::now());
        $this->profiler->listen();

        if ($this->options->selfTest) {
            $this->plantFaults();
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function (): void {
                $this->stop = true;
                $this->say('Interrupted: finishing the current tick and writing the report.');
            });
        }

        $end = $this->options->start + $this->options->hours * 3600;
        $nextDay = $this->options->start + 86400;
        $first = true;

        while (!$this->stop && SimClock::now() < $end) {
            SimClock::set(min($end, SimClock::now() + $this->options->step));
            $this->tick();
            $this->lastTick = SimClock::now();

            if ($first) {
                $this->assertClockShim();
                $first = false;
            }

            $this->guarded('tracking', fn () => $this->tracker->afterTick(SimClock::now()));
            $this->guarded('grants', fn () => $this->grantDue());

            if (SimClock::now() >= $nextDay || SimClock::now() >= $end || $this->stop) {
                $this->guarded('daily checks', fn () => $this->daily());
                $nextDay += 86400;
            }
        }

        $report = new ReportWriter($this->options, $this->recorder, $this->tracker, $this->profiler);
        $report->write($this->summary(), $this->progress, $this->botIds, $this->adminId);
        $this->say('Report: ' . $this->options->report);

        return $this->options->selfTest ? $this->selfTestResult() : 0;
    }

    /**
     * The harness's own work between ticks: a failure is recorded, the run
     * goes on.
     */
    private function guarded(string $what, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            $this->recorder->throwable($e, 'harness: ' . $what);
        }
    }

    /**
     * Known faults on the admin's planet (no bot touches it) and one bot,
     * each of which a check has to report.
     */
    private function plantFaults(): void
    {
        $planet = (int) DB::table('users')->where('id', $this->adminId)->value('home_planet_id');
        DB::table('planets')->where('planet_id', $planet)->update(['planet_metal' => -5, 'planet_b_hangar_id' => '202,x;']);
        DB::table('ships')->where('ship_planet_id', $planet)->update(['ship_small_cargo_ship' => -1]);
        DB::table('buildings')->where('building_planet_id', $planet)->update(['building_metal_mine' => -1]);
        DB::table('users')->where('id', $this->botIds[0] ?? 0)->update(['user_bot_next_action' => SimClock::now() + 10 * 86400]);
        DB::table('fleets')->insert([
            'fleet_owner' => $this->adminId, 'fleet_mission' => 99, 'fleet_amount' => 1, 'fleet_array' => '202,1',
            'fleet_start_time' => SimClock::now() - 7200, 'fleet_end_time' => SimClock::now() - 3600, 'fleet_mess' => 1,
            'fleet_start_galaxy' => 1, 'fleet_start_system' => 1, 'fleet_start_planet' => 1, 'fleet_start_type' => 1,
            'fleet_end_galaxy' => 1, 'fleet_end_system' => 2, 'fleet_end_planet' => 1, 'fleet_end_type' => 1,
        ]);
        report(new RuntimeException('simulator self-test: reported exception'));

        // the third tick fails part-way (resolving the auction service)
        $resolved = 0;
        app()->bind(\App\Services\Game\AuctionService::class, function ($app) use (&$resolved) {
            if (++$resolved === 3) {
                throw new RuntimeException('simulator self-test: failing tick');
            }

            return $app->build(\App\Services\Game\AuctionService::class);
        });
        $this->say('Self-test: faults planted.');
    }

    private function selfTestResult(): int
    {
        $expected = [
            'negative_resources_or_debris', 'negative_ship_count', 'hangar_queue_malformed', 'stuck_fleet',
            'building_level_decreased', 'field_counter_differs_from_building_levels', 'bot_idle_over_an_hour',
        ];
        $missing = array_values(array_diff($expected, array_keys($this->recorder->violations)));
        $reported = array_filter($this->recorder->errors, fn ($e) => str_contains($e['message'], 'simulator self-test: reported'));
        $failed = array_filter($this->recorder->errors, fn ($e) => $e['source'] === 'tick' && str_contains($e['message'], 'simulator self-test: failing tick'));

        if ($reported === []) {
            $missing[] = 'report()ed exception';
        }

        if ($failed === []) {
            $missing[] = 'exception thrown out of the tick';
        }

        $this->say($missing === [] ? 'Self-test passed: every planted fault was reported.' : 'Self-test FAILED, not reported: ' . implode(', ', $missing));

        return $missing === [] ? 0 : 3;
    }

    private function bootLaravel(): void
    {
        if ($this->options->moonUnit > 0) {
            \define('MOON_UNIT_PROB', $this->options->moonUnit);
        }

        $app = require $this->root . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        /** @var array<string, mixed> $mysql */
        $mysql = config('database.connections.mysql');
        $logPath = (string) preg_replace('/\.md$/', '', $this->options->report) . '.log';
        @mkdir(dirname($logPath), 0775, true);
        @unlink($logPath);

        config([
            'database.connections.sim' => array_merge($mysql, [
                'host' => $this->options->dbHost,
                'port' => (string) $this->options->dbPort,
                'database' => $this->options->database,
                'username' => $this->options->dbUser,
                'password' => $this->options->dbPass,
                'prefix' => 'xgp_',
            ]),
            'database.default' => 'sim',
            'logging.channels.sim' => ['driver' => 'single', 'path' => $logPath, 'level' => 'debug'],
            'logging.default' => 'sim',
            'logging.deprecations' => ['channel' => 'sim', 'trace' => false],
            'mail.default' => 'array',
            'cache.default' => 'array',
            'version.files' => config('version.files') ?: '4.0.0',
        ]);
        DB::purge('sim');
        Log::setDefaultDriver('sim');

        Str::createRandomStringsUsing(static function (int $length): string {
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            $out = '';

            for ($i = 0; $i < $length; $i++) {
                $out .= $chars[mt_rand(0, 61)];
            }

            return $out;
        });

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $exception = $event->context['exception'] ?? null;

            if ($exception instanceof Throwable) {
                $this->recorder->throwable($exception, 'report/log:' . $event->level);

                return;
            }

            $this->recorder->logged($event->level, $event->message);
        });

        $name = DB::connection('sim')->getDatabaseName();

        if (preg_match('/_sim2?$/', (string) $name) !== 1) {
            throw new RuntimeException('Safety check failed: the connection points to ' . $name);
        }
    }

    /**
     * A fresh universe as the installer makes it, an admin like the
     * installer's, the options for the run and the bots.
     */
    private function install(): void
    {
        $this->say(sprintf(
            'Installing a fresh universe in %s (seed %d, start %s, %d bots, %d h, step %d s)',
            $this->options->database,
            $this->options->seed,
            \date('Y-m-d H:i', $this->options->start),
            $this->options->bots,
            $this->options->hours,
            $this->options->step
        ));

        $commands = [
            ['db:wipe', ['--force' => true, '--database' => 'sim']],
            ['migrate:install', ['--database' => 'sim']],
            ['migrate', ['--path' => 'database/migrations/install', '--force' => true, '--database' => 'sim']],
            ['xgp:mark-installed-updates', ['--all' => true]],
            ['db:seed', ['--class' => 'ChangelogTableSeeder', '--force' => true, '--database' => 'sim']],
            ['db:seed', ['--class' => 'LanguagesTableSeeder', '--force' => true, '--database' => 'sim']],
            ['db:seed', ['--class' => 'OptionsTableSeeder', '--force' => true, '--database' => 'sim']],
        ];

        foreach ($commands as [$command, $arguments]) {
            if (Artisan::call($command, $arguments) !== 0) {
                throw new RuntimeException($command . ' failed: ' . Artisan::output());
            }
        }

        $settings = app(SettingsService::class);
        $settings->write('bots_play', 1);
        $settings->write('auto_backup', 0);

        foreach ($this->options->gameOptions as $name => $value) {
            if (!DB::table('options')->where('name', $name)->exists()) {
                throw new RuntimeException('Unknown game option: ' . $name);
            }

            $settings->write($name, $value);
        }

        $this->adminId = $this->createAdmin();
        $created = app(BotService::class)->createBots($this->options->bots, $this->options->galaxyFrom, $this->options->galaxyTo);

        if ($created < $this->options->bots) {
            $this->say("Warning: only {$created} of {$this->options->bots} bots could be placed.");
        }

        $this->botIds = array_map('intval', User::where('user_is_bot', true)->orderBy('id')->pluck('id')->all());
        $this->say(sprintf('Universe ready: admin #%d, %d bots, %d game namespaces on the simulated clock.', $this->adminId, count($this->botIds), count($this->shimmed)));
    }

    private function createAdmin(): int
    {
        return DB::transaction(function (): int {
            $admin = new User([
                'name' => 'admin',
                'email' => 'admin@sim.local',
                'password' => Str::random(32),
                'home_planet_id' => 0,
                'current_planet' => 0,
                'lastip' => '127.0.0.1',
                'ip_at_reg' => '127.0.0.1',
                'agent' => 'simulator',
                'current_page' => '',
                'register_time' => SimClock::now(),
                'onlinetime' => SimClock::now(),
            ]);
            $admin->authlevel = UserRanksEnumerator::ADMIN;
            $admin->save();
            $admin->preferences()->create();
            $admin->premium()->create();
            $admin->research()->create();
            $admin->stats()->create();

            (new PlanetLib())->setNewPlanet(1, 1, 1, $admin->id, '', true);
            $planetId = Planets::where('planet_user_id', $admin->id)->value('planet_id');
            User::where('id', $admin->id)->update([
                'home_planet_id' => $planetId, 'current_planet' => $planetId, 'galaxy' => 1, 'system' => 1, 'planet' => 1,
            ]);

            return (int) $admin->id;
        });
    }

    private function tick(): void
    {
        $started = hrtime(true);

        try {
            if ($this->options->profile) {
                $this->profiledTick();
            } else {
                new UpdatesLibrary();
            }
        } catch (Throwable $e) {
            $this->recorder->throwable($e, 'tick');

            while (DB::transactionLevel() > 0) {
                DB::rollBack();
                $this->recorder->logged('harness', 'An open transaction was left behind by a failing tick and rolled back.');
            }
        }

        $this->profiler->ticks[] = (hrtime(true) - $started) / 1e6;
    }

    /**
     * The tick's steps one by one, timed. Mirrors UpdatesLibrary::__construct;
     * if it no longer has these steps, the plain tick runs instead.
     */
    private function profiledTick(): void
    {
        $class = new ReflectionClass(UpdatesLibrary::class);
        $steps = ['cleanUp', 'createBackup', 'updateFleets', 'updateStatistics'];

        foreach ($steps as $step) {
            if (!$class->hasMethod($step)) {
                $this->profiler->phase('whole tick (profile fallback)', fn () => new UpdatesLibrary());

                return;
            }
        }

        $tick = $class->newInstanceWithoutConstructor();
        $call = fn (string $method) => fn () => $class->getMethod($method)->invoke($tick);
        $this->profiler->phase('cleanUp', $call('cleanUp'));
        $this->profiler->phase('createBackup', $call('createBackup'));
        $this->profiler->phase('updateFleets (missions)', $call('updateFleets'));
        $this->profiler->phase('relocations', fn () => app(\App\Services\Game\RelocationService::class)->settleDue(SimClock::now()));
        $this->profiler->phase('updateStatistics', $call('updateStatistics'));
        $this->profiler->phase('auctions', fn () => app(\App\Services\Game\AuctionService::class)->settleEnded(SimClock::now()));
        $this->profiler->phase('bots playDue', fn () => app(\App\Services\Game\Bots\BotPlayerService::class)->playDue(SimClock::now()));
    }

    /**
     * The tick writes bots_last_run = time(): if that is not the simulated
     * time, the game does not see the simulated clock and the run is void.
     */
    private function assertClockShim(): void
    {
        $lastRun = (int) DB::table('options')->where('name', 'bots_last_run')->value('value');

        if ($lastRun !== SimClock::now()) {
            throw new RuntimeException(sprintf('Clock shim not effective: bots_last_run=%d, simulated time=%d.', $lastRun, SimClock::now()));
        }
    }

    /**
     * Scenario grants: ships every bot gets on its home planet at an hour.
     */
    private function grantDue(): void
    {
        $hour = intdiv(SimClock::now() - $this->options->start, 3600);
        $names = app(\App\Core\GameObjects\GameObjectRegistry::class)->names();

        foreach ($this->options->grants as $index => [$at, $ship, $amount, $bots]) {
            if ($hour < $at || isset($this->granted[$index])) {
                continue;
            }

            $this->granted[$index] = true;
            $column = $names[$ship] ?? throw new RuntimeException('--grant: unknown ship id ' . $ship);
            $receivers = $bots > 0 ? array_slice($this->botIds, 0, $bots) : $this->botIds;
            $homes = User::whereIn('id', $receivers)->pluck('home_planet_id')->all();
            DB::table('ships')->whereIn('ship_planet_id', $homes)->increment($column, $amount);
            $this->say(sprintf('Scenario at sim h %d: %d bots got %d × %s each.', $hour, count($receivers), $amount, $column));
        }
    }

    private function daily(): void
    {
        $this->invariants->check(SimClock::now());
        $points = DB::table('users_statistics')->whereIn('user_statistic_user_id', $this->botIds)->avg('user_statistic_total_points');
        $flying = [];

        foreach ($this->tracker->inFlight() as $mission => $count) {
            $flying[] = $mission . ' ' . $count;
        }

        $wall = microtime(true) - $this->wallStart;
        $line = sprintf(
            'Day %3d/%d | sim h %6.1f | wall %s | avg points %9.1f | fleets in flight: %s | errors %d (%d kinds) | violations %d (%d kinds) | mem %d MB',
            (int) ceil((SimClock::now() - $this->options->start) / 86400),
            (int) ceil($this->options->hours / 24),
            (SimClock::now() - $this->options->start) / 3600,
            gmdate('H:i:s', (int) $wall),
            (float) $points,
            $flying === [] ? 'none' : implode(', ', $flying),
            $this->recorder->errorCount,
            count($this->recorder->errors),
            $this->recorder->violationCount,
            count($this->recorder->violations),
            intdiv(memory_get_peak_usage(true), 1048576)
        );
        $this->progress[] = $line;
        $this->say($line);

        (new ReportWriter($this->options, $this->recorder, $this->tracker, $this->profiler))
            ->write($this->summary(), $this->progress, $this->botIds, $this->adminId);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $wall = microtime(true) - $this->wallStart;
        $simHours = ($this->lastTick - $this->options->start) / 3600;

        return [
            'database' => $this->options->database,
            'seed' => $this->options->seed,
            'start' => \date('c', $this->options->start),
            'bots' => $this->options->bots,
            'hours_requested' => $this->options->hours,
            'hours_simulated' => round($simHours, 2),
            'step_s' => $this->options->step,
            'galaxies' => $this->options->galaxyFrom . '-' . $this->options->galaxyTo,
            'options' => $this->options->gameOptions,
            'profile_mode' => $this->options->profile,
            'wall_s' => round($wall, 1),
            'sim_hours_per_wall_s' => $wall > 0 ? round($simHours / $wall, 3) : 0,
            'peak_memory_mb' => intdiv(memory_get_peak_usage(true), 1048576),
            'shimmed_namespaces' => count($this->shimmed),
            'interrupted' => $this->stop,
            'command' => 'php -d memory_limit=2G ' . implode(' ', array_map('escapeshellarg', $GLOBALS['argv'] ?? [])),
        ];
    }

    private function say(string $line): void
    {
        fwrite(STDOUT, '[' . \date('H:i:s') . '] ' . $line . "\n");
    }
}

/**
 * Writes the Markdown and JSON report.
 */
final class ReportWriter
{
    public function __construct(
        private readonly Options $options,
        private readonly Recorder $recorder,
        private readonly WorldTracker $tracker,
        private readonly Profiler $profiler,
    ) {
    }

    /**
     * @param array<string, mixed> $summary
     * @param list<string>         $progress
     * @param list<int>            $botIds
     */
    public function write(array $summary, array $progress, array $botIds, int $adminId): void
    {
        $bots = [];

        foreach ($botIds as $botId) {
            $bots[] = $this->bot($botId);
        }

        $data = [
            'summary' => $summary,
            'performance' => $this->performance(),
            'global' => $this->global($adminId),
            'bots' => $bots,
            'errors' => array_values($this->sorted($this->recorder->errors)),
            'violations' => $this->sorted($this->recorder->violations),
            'progress' => $progress,
        ];

        $base = (string) preg_replace('/\.md$/', '', $this->options->report);
        @mkdir(dirname($base), 0775, true);
        file_put_contents($base . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        file_put_contents(str_ends_with($this->options->report, '.md') ? $this->options->report : $base . '.md', $this->markdown($data));
    }

    /**
     * @return array<string, mixed>
     */
    private function bot(int $botId): array
    {
        $user = DB::table('users')->where('id', $botId)->first(['id', 'name', 'home_planet_id', 'onlinetime']);

        if ($user === null) {
            return ['id' => $botId, 'deleted' => true];
        }

        $stats = DB::table('users_statistics')->where('user_statistic_user_id', $botId)->first();
        $planets = DB::table('planets')->where('planet_user_id', $botId)->where('planet_destroyed', 0)->get();
        $planetIds = $planets->pluck('planet_id')->all();
        $home = (array) (DB::table('buildings')->where('building_planet_id', $user->home_planet_id)->first() ?? []);
        $research = (array) (DB::table('research')->where('research_user_id', $botId)->first() ?? []);
        $ships = $this->sums('ships', 'ship_planet_id', 'ship_', $planetIds);
        $defences = $this->sums('defenses', 'defense_planet_id', 'defense_', $planetIds);

        $fleets = [];

        foreach ($this->tracker->fleets as $fleet) {
            if ($fleet['owner'] === $botId) {
                $name = MISSIONS[$fleet['mission']] ?? 'mission ' . $fleet['mission'];
                $fleets[$name] = ($fleets[$name] ?? 0) + 1;
            }
        }

        $battles = 0;

        foreach ($this->tracker->battles as $battle) {
            if (in_array((string) $botId, preg_split('/[^0-9]+/', $battle['owners']) ?: [], true)) {
                $battles++;
            }
        }

        return [
            'id' => $botId,
            'name' => $user->name,
            'points' => round((float) ($stats->user_statistic_total_points ?? 0), 1),
            'rank' => (int) ($stats->user_statistic_total_rank ?? 0),
            'planets' => $planets->where('planet_type', 1)->count(),
            'moons' => $planets->where('planet_type', 3)->count(),
            'home_buildings' => self::nonZero($home, 'building_', ['building_planet_id', 'building_id']),
            'research' => self::nonZero($research, 'research_', ['research_user_id', 'research_id', 'research_current_research']),
            'ships' => $ships,
            'defences' => $defences,
            'fleets_sent' => $fleets,
            'battles' => $battles,
            'expeditions' => $this->tracker->expeditions[$botId] ?? [],
            'messages' => $this->tracker->messages[$botId] ?? [],
            'resources' => [
                'metal' => (int) $planets->sum('planet_metal'),
                'crystal' => (int) $planets->sum('planet_crystal'),
                'deuterium' => (int) $planets->sum('planet_deuterium'),
                'dark_matter' => (int) DB::table('premium')->where('premium_user_id', $botId)->value('premium_dark_matter'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function global(int $adminId): array
    {
        $outcomes = [];
        $expeditions = [];
        $colonies = 0;
        $moons = 0;

        foreach ($this->tracker->fleets as $fleet) {
            $name = MISSIONS[$fleet['mission']] ?? 'mission ' . $fleet['mission'];
            $outcomes[$name][$fleet['outcome'] ?? 'in flight'] = ($outcomes[$name][$fleet['outcome'] ?? 'in flight'] ?? 0) + 1;
        }

        foreach ($this->tracker->expeditions as $byOutcome) {
            foreach ($byOutcome as $outcome => $count) {
                $expeditions[$outcome] = ($expeditions[$outcome] ?? 0) + $count;
            }
        }

        foreach ($this->tracker->newPlanets as $planet) {
            $planet['type'] === 1 ? $colonies++ : $moons++;
        }

        $debris = DB::table('planets')->where(fn ($q) => $q->where('planet_debris_metal', '>', 0)->orWhere('planet_debris_crystal', '>', 0))
            ->selectRaw('COUNT(*) AS fields, COALESCE(SUM(planet_debris_metal), 0) AS metal, COALESCE(SUM(planet_debris_crystal), 0) AS crystal')->first();
        $deepSpace = Schema::hasTable('deep_space_debris')
            ? DB::table('deep_space_debris')->selectRaw('COUNT(*) AS fields, COALESCE(SUM(metal), 0) AS metal, COALESCE(SUM(crystal), 0) AS crystal')->first()
            : null;

        return [
            'fleets_by_mission_and_outcome' => $outcomes,
            'battle_reports' => count($this->tracker->battles),
            'battle_reports_attacker_destroyed' => count(array_filter($this->tracker->battles, fn ($b) => $b['destroyed'] > 0)),
            'expeditions_by_outcome' => $expeditions,
            'colonizations' => $colonies,
            'moons_created' => $moons,
            'debris_fields' => (array) $debris,
            'deep_space_debris' => (array) $deepSpace,
            'admin_points' => round((float) DB::table('users_statistics')->where('user_statistic_user_id', $adminId)->value('user_statistic_total_points'), 1),
            'users' => (int) DB::table('users')->count(),
            'planets' => (int) DB::table('planets')->where('planet_destroyed', 0)->count(),
            'messages_by_kind' => $this->messageTotals(),
            'trading' => $this->profiler->trades + [
                'auctions' => (int) DB::table('auctions')->count(),
                'auctions_won' => (int) DB::table('auctions')->whereNotNull('winner_id')->count(),
                'auction_bid_value' => (int) DB::table('auction_bids')->sum('value'),
                'boosters_used_on_planets' => (int) DB::table('planet_boosters')->count(),
                'items_left' => (int) DB::table('user_items')->sum('amount'),
            ],
            'cooperation' => $this->cooperation(),
        ];
    }

    /**
     * Alliances at the end, ACS groups, battles with more than two players
     * (ACS or defenders holding at the planet) and missile strikes.
     *
     * @return array<string, mixed>
     */
    private function cooperation(): array
    {
        $alliances = [];

        foreach (DB::table('alliance')->get(['alliance_id', 'alliance_tag', 'alliance_class']) as $alliance) {
            $alliances[$alliance->alliance_tag . ' (class ' . $alliance->alliance_class . ')'] = DB::table('users')->where('ally_id', $alliance->alliance_id)->pluck('name')->all();
        }

        return [
            'alliances' => $alliances,
            'acs_groups' => count($this->tracker->groups),
            'acs_groups_joined' => count(array_filter($this->tracker->groups, static fn (array $owners): bool => count($owners) > 1)),
            'battles_with_more_than_two_players' => count(array_filter($this->tracker->battles, static fn (array $b): bool => count(array_unique(explode(',', $b['owners']))) > 2)),
            'missile_strikes' => intdiv($this->tracker->missileMessages, 2),
            'defences_destroyed_by_missiles' => intdiv($this->tracker->missileDestroyed, 2),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function messageTotals(): array
    {
        $totals = [];

        foreach ($this->tracker->messages as $kinds) {
            foreach ($kinds as $kind => $count) {
                $totals[$kind] = ($totals[$kind] ?? 0) + $count;
            }
        }

        ksort($totals);

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    private function performance(): array
    {
        $queries = $this->profiler->queries;
        uasort($queries, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        $top = [];

        foreach (array_slice($queries, 0, 15, true) as $sql => $entry) {
            $top[] = ['sql' => $sql, 'count' => $entry['count'], 'total_s' => round($entry['ms'] / 1000, 2), 'avg_ms' => round($entry['ms'] / max(1, $entry['count']), 3)];
        }

        $phases = $this->profiler->phases;
        arsort($phases);

        return [
            'ticks' => $this->profiler->tickStats(),
            'phases_s' => array_map(fn ($ms) => round($ms / 1000, 2), $phases),
            'top_sql_by_time' => $top,
        ];
    }

    /**
     * @param array<int, int> $planetIds
     *
     * @return array<string, int>
     */
    private function sums(string $table, string $key, string $prefix, array $planetIds): array
    {
        if ($planetIds === []) {
            return [];
        }

        $columns = array_filter(Schema::getColumnListing($table), fn ($c) => str_starts_with($c, $prefix) && $c !== $key && $c !== $prefix . 'id');
        $row = (array) DB::table($table)->whereIn($key, $planetIds)
            ->selectRaw(implode(', ', array_map(fn ($c) => 'COALESCE(SUM(`' . $c . '`), 0) AS `' . $c . '`', $columns)))->first();

        return self::nonZero($row, $prefix, []);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $skip
     *
     * @return array<string, int>
     */
    private static function nonZero(array $row, string $prefix, array $skip): array
    {
        $out = [];

        foreach ($row as $column => $value) {
            if (str_starts_with($column, $prefix) && !in_array($column, $skip, true) && is_numeric($value) && (int) $value !== 0) {
                $out[substr($column, strlen($prefix))] = (int) $value;
            }
        }

        return $out;
    }

    /**
     * @template T of array{count: int}
     *
     * @param array<string, T> $entries
     *
     * @return array<string, T>
     */
    private function sorted(array $entries): array
    {
        uasort($entries, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $entries;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function markdown(array $data): string
    {
        /** @var array<string, mixed> $s */
        $s = $data['summary'];
        /** @var array<string, mixed> $g */
        $g = $data['global'];
        /** @var array<string, mixed> $perf */
        $perf = $data['performance'];
        $md = [];
        $md[] = '# Bot simulation report';
        $md[] = '';
        $md[] = sprintf(
            '%d bots, %s of %d simulated hours (step %d s), database `%s`, seed %d, start %s%s.',
            $s['bots'],
            $s['hours_simulated'],
            $s['hours_requested'],
            $s['step_s'],
            $s['database'],
            $s['seed'],
            $s['start'],
            $s['interrupted'] ? ', INTERRUPTED' : ''
        );
        $md[] = '';
        $md[] = sprintf(
            'Wall time %s s, %s simulated hours per wall second, peak memory %d MB, %d game namespaces on the simulated clock%s.',
            $s['wall_s'],
            $s['sim_hours_per_wall_s'],
            $s['peak_memory_mb'],
            $s['shimmed_namespaces'],
            $s['profile_mode'] ? ', profile mode' : ''
        );
        $md[] = '';
        $md[] = '`' . $s['command'] . '`';
        $md[] = '';

        $md[] = '## Errors (' . $this->recorder->errorCount . ' in ' . count($this->recorder->errors) . ' groups)';
        $md[] = '';

        foreach ($data['errors'] as $error) {
            $md[] = sprintf(
                '- **%d×** `%s` %s — %s (%s), sim h %s–%s',
                $error['count'],
                $error['class'],
                str_replace("\n", ' ', mb_substr($error['message'], 0, 400)),
                $error['location'],
                $error['source'],
                $this->recorder->simHour($error['first']),
                $this->recorder->simHour($error['last'])
            );

            foreach (array_slice($error['trace'], 0, 8) as $frame) {
                $md[] = '    - `' . $frame . '`';
            }
        }

        $md[] = '';
        $md[] = '## Invariant violations (' . $this->recorder->violationCount . ' in ' . count($this->recorder->violations) . ' groups)';
        $md[] = '';

        foreach ($data['violations'] as $type => $violation) {
            $md[] = sprintf('- **%s**: %d×, sim h %s–%s', $type, $violation['count'], $this->recorder->simHour($violation['first']), $this->recorder->simHour($violation['last']));

            foreach (array_slice($violation['examples'], 0, 4) as $example) {
                $md[] = '    - `' . json_encode($example, JSON_UNESCAPED_SLASHES) . '`';
            }
        }

        $md[] = '';
        $md[] = '## Progress';
        $md[] = '';
        $md[] = '```';
        array_push($md, ...$data['progress']);
        $md[] = '```';
        $md[] = '';
        $md[] = '## World';
        $md[] = '';
        $md[] = sprintf('- Battle reports: %d (%d over after the first round)', $g['battle_reports'], $g['battle_reports_attacker_destroyed']);
        $md[] = '- Colonizations: ' . $g['colonizations'] . ', moons created: ' . $g['moons_created'];
        $md[] = '- Debris fields: `' . json_encode($g['debris_fields']) . '`, deep space debris: `' . json_encode($g['deep_space_debris']) . '`';
        $md[] = '- Expeditions by outcome: `' . json_encode($g['expeditions_by_outcome']) . '`';
        $md[] = '- Messages by kind: `' . json_encode($g['messages_by_kind']) . '`';
        $md[] = '- Trading: `' . json_encode($g['trading']) . '`';
        $md[] = '- Users ' . $g['users'] . ', planets and moons ' . $g['planets'] . ', admin points ' . $g['admin_points'];
        $md[] = '- Fleets by mission and outcome (outcome is inferred from when the fleet row vanished):';

        foreach ($g['fleets_by_mission_and_outcome'] as $mission => $outcomes) {
            $md[] = '    - ' . $mission . ': `' . json_encode($outcomes) . '`';
        }

        $c = $g['cooperation'];
        $md[] = '';
        $md[] = '## Cooperation';
        $md[] = '';
        $md[] = '- Alliances: `' . json_encode($c['alliances'], JSON_UNESCAPED_UNICODE) . '`';
        $md[] = sprintf('- ACS groups: %d (%d joined by allies), battles with more than two players: %d', $c['acs_groups'], $c['acs_groups_joined'], $c['battles_with_more_than_two_players']);
        $md[] = sprintf('- Missile strikes: %d, defences destroyed by missiles: %d', $c['missile_strikes'], $c['defences_destroyed_by_missiles']);
        $md[] = '';
        $md[] = '## Bots';
        $md[] = '';
        $md[] = '| Bot | Points | Rank | Planets | Moons | Metal | Crystal | Deut | Fleets sent | Battles |';
        $md[] = '|---|---:|---:|---:|---:|---:|---:|---:|---|---:|';

        foreach ($data['bots'] as $bot) {
            if (isset($bot['deleted'])) {
                $md[] = '| #' . $bot['id'] . ' (deleted) | | | | | | | | | |';

                continue;
            }

            $md[] = sprintf(
                '| %s | %s | %d | %d | %d | %s | %s | %s | %s | %d |',
                $bot['name'],
                number_format($bot['points'], 1, '.', ''),
                $bot['rank'],
                $bot['planets'],
                $bot['moons'],
                number_format($bot['resources']['metal'], 0, '.', ' '),
                number_format($bot['resources']['crystal'], 0, '.', ' '),
                number_format($bot['resources']['deuterium'], 0, '.', ' '),
                $bot['fleets_sent'] === [] ? '-' : $this->pairs($bot['fleets_sent']),
                $bot['battles']
            );
        }

        $md[] = '';

        foreach ($data['bots'] as $bot) {
            if (isset($bot['deleted'])) {
                continue;
            }

            $md[] = '### ' . $bot['name'] . ' (#' . $bot['id'] . ')';
            $md[] = '';
            $md[] = '- Home buildings: ' . $this->pairs($bot['home_buildings']);
            $md[] = '- Research: ' . $this->pairs($bot['research']);
            $md[] = '- Ships: ' . $this->pairs($bot['ships']);
            $md[] = '- Defences: ' . $this->pairs($bot['defences']);
            $md[] = '- Expeditions: ' . $this->pairs($bot['expeditions']) . '; messages: ' . $this->pairs($bot['messages']);
            $md[] = '- Dark matter: ' . $bot['resources']['dark_matter'];
            $md[] = '';
        }

        $md[] = '## Performance';
        $md[] = '';
        $md[] = '- Ticks: `' . json_encode($perf['ticks']) . '`';

        if ($perf['phases_s'] !== []) {
            $md[] = '- Tick phases (s): `' . json_encode($perf['phases_s']) . '`';
        }

        $md[] = '';
        $md[] = '| SQL (normalized) | Count | Total s | Avg ms |';
        $md[] = '|---|---:|---:|---:|';

        foreach ($perf['top_sql_by_time'] as $query) {
            $md[] = sprintf('| `%s` | %d | %s | %s |', str_replace('|', '\\|', $query['sql']), $query['count'], $query['total_s'], $query['avg_ms']);
        }

        $md[] = '';

        return implode("\n", $md);
    }

    /**
     * @param array<string, int> $pairs
     */
    private function pairs(array $pairs): string
    {
        if ($pairs === []) {
            return '-';
        }

        $out = [];

        foreach ($pairs as $key => $value) {
            $out[] = $key . ' ' . $value;
        }

        return implode(', ', $out);
    }
}

// ---------------------------------------------------------------------------

$root = dirname(__DIR__);
$options = Options::fromArgv($root);

// before any game code runs: the clock, the seed, the shims
SimClock::init($options->start);
mt_srand($options->seed);
$shimmed = ClockShim::install([$root . '/app', $root . '/database']);

// a vendor/ shared with another checkout (a symlink) maps App\ to that
// checkout: load this checkout's classes and constants first
$composerFiles = is_file($root . '/vendor/composer/autoload_files.php') ? require $root . '/vendor/composer/autoload_files.php' : [];

foreach (is_array($composerFiles) ? $composerFiles : [] as $fileId => $path) {
    if (is_string($path) && str_ends_with($path, '/app/constants.php') && realpath($path) !== realpath($root . '/app/constants.php')) {
        require $root . '/app/constants.php';
        $GLOBALS['__composer_autoload_files'][$fileId] = true;
    }
}

require $root . '/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($root): void {
    $map = ['App\\' => '/app/', 'Database\\Seeders\\' => '/database/seeders/', 'Database\\Factories\\' => '/database/factories/'];

    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
}, true, true);

SimClock::set($options->start);

try {
    exit((new Simulator($options, $root, $shimmed))->run());
} catch (Throwable $e) {
    fwrite(STDERR, 'Simulation aborted: ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
