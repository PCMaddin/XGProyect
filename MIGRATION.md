# Migrations-Roadmap: `legacy/` → `app/`  ✅ abgeschlossen

> XG Proyect war mitten in einem **Strangler-Fig-Umbau** vom alten prozeduralen
> XGP-Code (`legacy/`) nach Laravel 12 (`app/`). Dieses Dokument beschreibt den
> Weg dorthin; die Abschnitte unten sind das Protokoll der einzelnen Wellen.

## Stand

| | |
|---|---|
| `legacy/` | **gelöscht**, ebenso der `Xgp\`-Autoload-Eintrag |
| Game-Controller | alle 38 Spielseiten nativ in `app/`, vor jeder Seite läuft `GameBootstrap` |
| Bibliotheken | alle in `app/` (Missions + BattleEngine unter `App\Libraries\…`) |
| Analyse-Schuld | **0 PHPStan- (Level 9) + 0 PHPMD-Einträge**, ausgehend von 1.337 / 682 |
| Tests | 994 PHPUnit-Tests (27 davon gegen MariaDB), dazu PHPStan, PHPMD (roh und Gate), php-cs-fixer und `composer audit` über das ganze Repo |
| Sprachen | Englisch, Spanisch, Deutsch (Schlüssel und Platzhalter per Test gleich) |
| Smoke-Test | Installer, alle Spiel- und Admin-Seiten in drei Sprachen und die wichtigsten Spielabläufe gegen MariaDB im Browser durchgespielt (siehe unten) |

> **Hinweis CI:** `pcmaddin/xgproyect` ist ein **Fork** von
> `XGProyect/XGProyect`. GitHub schaltet Actions in Forks ab, bis der
> Besitzer sie im Tab „Actions“ einmal freigibt; deshalb gibt es keinen
> einzigen Workflow-Lauf. Alle sechs Jobs aus `.github/workflows/php.yml`
> (PHPUnit inkl. `composer validate`, php-cs-fixer, PHPStan, PHPMD,
> `composer audit`) laufen lokal unter CI-Bedingungen grün, also ohne `.env`
> und ohne Installer-Konfiguration. Dafür setzt `phpunit.xml` jetzt einen
> Test-`APP_KEY`, und der doppelte Lockfile-Schritt ist aus dem Workflow
> entfernt.

### Abschluss der Migration

1. **Enumeratoren** → `App\Core\Enumerators` (nur Namespace; die Konstanten-
   klassen bleiben, weil der Spielcode sie durchgehend als Int-Keys und
   -Werte nutzt).
2. **Helpers** → `App\Helpers`, **SQL-Concerns** (`PreparesLegacySql`,
   `BindsLegacyQueries`) → `App\Core\Concerns`.
3. **Missions, BattleEngine, Entities, `Objects`** → `app/`, Tests von
   `tests/Unit/Legacy` nach `tests/Unit/App`; `legacy/` und alle
   `legacy/`-Pfade in PHPStan, PHPMD, php-cs-fixer und CI entfernt.
4. **`Objects` (deprecated Adapter) entfernt:** Controller, Tick, Galaxie-,
   Flotten- und Entwicklungs-Bibliothek und die Missions lesen Namen, Preise,
   Kampfwerte und Produktionsformeln direkt aus der `GameObjectRegistry`
   (Container-Singleton; die Controller bauten über `new Objects()` pro
   Request eine eigene Registry). `MissionObjects` liefert die Array-Formen
   der Kampf-Engine; vor dem Löschen wurde geprüft, dass Arrays und
   Formelergebnisse identisch sind.
5. **Entities (deprecated) durch Eloquent ersetzt:** `Alliances`, `Buddy`,
   `Fleets`, `AcsFleets`, `Premium`, `Researches` und `ResourceMarket` liefern
   Eloquent-Modelle, die Aufrufer lesen Properties statt Getter (128 Stellen).
   Damit das Verhalten gleich bleibt, füllt `HydratesModels::hydrateRow()`
   fehlende und NULL-Werte typgerecht mit `0`/`''`/`false` vor, so wie die
   Getter es taten; damit stimmen auch die `@property`-Typen. Fehlende Casts
   und Properties in den Modellen ergänzt (`Fleets`: Id, Menge, Ladung,
   Treibstoff; `Planets`: Id, Trümmerfeld; `Acs`: Id; `Alliance`:
   Mitgliederzahl). **Bugfix:** `Fleets::fleet_mess` war auf `boolean`
   gecastet, die Spalte kennt aber 0/1/2. Die Admin-Flottenliste zeigte
   stehende Flotten deshalb als „zurückkehrend“; `Acs::acs_planet_type`
   (1/3) hatte denselben Fehler. Beide sind jetzt `int`. Tests.

6. **Doppelte ID-Enums entfernt:** `App\Enums\Game\{BuildingId, ResearchId,
   ShipId, DefenseId, OfficerId}` doppelten die Enumeratoren, wurden aber nur
   in Tests genutzt. Die Enumeratoren sind die einzige Quelle der Ids; ein
   Test prüft, dass sie genau die registrierten Objekte ihrer Art benennen.
7. **„Legacy“ aus den Namen entfernt:** `LegacyController` → `GameController`
   (Konstante `PAGES`), `routes/legacy.php` → `routes/game.php`,
   Middleware-Gruppe `legacy` → `game`, `PreparesLegacySql` → `PreparesSql`,
   `BindsLegacyQueries` → `BindsQueries`, `LegacyView`/`Template::legacyView()`
   → `HaltWithView`/`Template::haltWithView()`; das unbenutzte
   `ProductionFormula::toLegacyArray()` ist weg. Die Abschnitte unten nennen
   noch die alten Namen (Protokoll).
8. **Bugfix: Konstanten nach `config:cache`.** Die Spielkonstanten
   (`ONE_DAY`, `DPATH`, Universumsgröße, Tabellennamen …) wurden als
   Config-Datei `config/legacy/constants.php` geladen. Nach
   `php artisan config:cache`, dem üblichen Produktions-Schritt, liest Laravel
   keine Config-Dateien mehr: Alle 53 Konstanten waren undefiniert und jede
   Spielseite brach ab. Die Datei liegt jetzt in `app/constants.php` und wird
   über Composers `autoload.files` geladen (geprüft mit gecachter Config).
   Regressionstest.

## Zentraler Befund: der geteilte Berechnungs-Layer

Die Migration ist **nicht** „Controller für Controller isoliert". `app/` hängt bereits
an 33 Stellen am Legacy-Library-Layer. Darunter liegt eine geteilte Rechen-Schicht,
die eigene Beachtung braucht:

- `Formulas` (281 Z.) → genutzt von **11** Controllern
- `FleetsLib` (500 Z.) → Fleet4, Galaxy, Movement, Overview, Phalanx
- `Missions` (Attack 762 / Spy 307 / Destroy 839 / Expedition 494) → Fleet3/4, Movement
- `UpdatesLibrary` (925 Z.) → Overview (Ressourcen-/Bau-Tick)
- `BattleEngine`, `GalaxyLib` (721 Z.) → Galaxie- & Kampf-Kern

Ohne diese Schicht separat zu migrieren, schleppt jede Controller-Portierung den
Legacy-Layer weiter mit.

---

## Welle 1 — Blattmodule / Quick-Wins  ✅ ABGESCHLOSSEN

Selbständige Module mit wenig Abhängigkeiten. Ziel: Blaupause & Momentum.

| Modul | Status | Notiz |
|---|---|---|
| Highscore | ✅ migriert | read-only Blaupause |
| Search | ✅ migriert | Suchtyp-Whitelist gehärtet |
| Chat | ✅ migriert | Notice-Bug + null-TypeError behoben |
| Fleetshortcuts | ✅ migriert | JSON-Injection, `mode=a`-Crash, getById-TypeError behoben |
| Premium | ✅ migriert | DM-Kauf parametrisiert |
| Planetlayer | ✅ migriert | Flotten-Lookup + Zerstör-Query parametrisiert |
| Resourcesettings | ✅ migriert | Produktions-Mathematik erhalten, POST parametrisiert |
| Trader + TraderOverview + TraderResources + TraderLayer | ✅ migriert/entfernt | 2 Live-Seiten migriert (ResourceMarket-Crash gefixt); `trader` + `traderLayer` als toter Code entfernt (Audit-Bug) |
| Defenses | ✅ migriert | `extends ShipyardController`, zusammen mit Shipyard in Welle 4 portiert |

**Blaupause (pro Modul, bewährt):** neuer typisierter Controller (`Request` statt
`$_POST`, `view()` statt `Template`, gebundene SQL-Parameter) → Eintrag in
`LegacyController::PROMOTED_PAGES` → Legacy-Datei löschen → Unit-Test nach
`tests/Unit/App/...` → zugehörige PHPStan/PHPMD-Baseline-Einträge entfernen.

**Wichtige Umgebungs-Erkenntnisse:** Tabellenkonstanten (`USERS`, `PLANETS`…) sind im
promoteten Pfad verfügbar (Laravel lädt `config/legacy/constants.php` bei jedem
Request); `DPATH` dagegen **nicht** — Bildpfade über `asset()` auflösen. Array-Über-
setzungen (`planet_type_short`, `officier.officiers`) über `trans()` statt `__()`.

## Welle 2 — Geteilter Berechnungs-Layer  ✅ abgeschlossen

Die statischen Klassen in `app/Libraries/` sind entfernt, alle Aufrufer nutzen
die typisierten Services:

| Alt | Neu |
|---|---|
| `Formulas` | `App\Services\Game\Formulas\FormulasService` |
| `DevelopmentsLib` | `App\Services\Game\Formulas\DevelopmentsService` (Hangar-Bauzeit in `UpdatesLibrary` für alle Schiffe/Verteidigungen × Stufen gegen die alte Formel verglichen: identisch) |
| `FleetsLib::get/setFleetShipsArray` | `App\Support\FleetShips::decode/encode` (`unserialize` nur ohne Objekte) |
| `FleetsLib::hasResources` | `FleetsService::carriesResources` |
| `FleetsLib::startLink/targetLink/flyingFleetsTable` u. a. | `App\Services\Game\FleetEventsService` (`eventRow` für Übersicht und Phalanx) |

## Welle 3 — Sozial & Kommunikation  ✅ abgeschlossen

| Modul | Status | Notiz |
|---|---|---|
| Buddies | ✅ migriert | Magic-Dispatch entfernt, Null-User-Bug behoben |
| Messages | ✅ migriert | SQL-Injection in Bulk-Delete behoben |
| Alliance | ✅ migriert | in 4 Etappen (public → writes → admin → Finale); 4 SQL-Injections + Template-Typo + Transfer-Key behoben |
| Federation | ✅ migriert | in Welle 4 (siehe dort); die Ansicht war noch in alter Template-Syntax, im Smoke-Test nach Blade umgestellt |

## Welle 4 — Gameplay-Kern

### Analyse-Befund: Controller vs. Engine sind entkoppelt

Die ursprüngliche Annahme *„erst Engine, dann Controller"* stimmt für die
restlichen Controller **nicht**. Die Fleet-Controller importieren nur den
Missions-**Enumerator** (Konstanten), nicht die Ausführungs-Engine:

- **Fleet1–4** sind der **Sende-Assistent** (Schiffe wählen → Ziel → Mission →
  Commit). Fleet4 macht nur `INSERT INTO FLEETS` + `UPDATE PLANETS/SHIPS` —
  dasselbe Muster wie das bereits migrierte Galaxy-`sendFleet`. **Kein**
  `BattleEngine`/`Attack`/`Spy`-Aufruf.
- **Federation** ist reine ACS-Verwaltung (Mitglieder, `ACS_MEMBERS`).
- Die **Kampf-/Missions-Engine** (`Missions` ~4.451 Z. + `BattleEngine` ~952 Z.
  + `MissionControlLib`) wird ausschließlich vom **Tick** ausgelöst
  (`UpdatesLibrary::updateFleets` → `arrivingFleets`), nicht von den Controllern.

→ Die 5 Rest-Controller sind als **Leaf-Migrationen** portierbar, ohne die
Engine anzufassen. Die Engine bleibt als Backend-Service bestehen und ist eine
**eigene, spätere** Refactoring-Welle ohne Zeitdruck.

### ⚙️ Tick-Lücke geschlossen

Promoted Seiten umgehen den Legacy-Bootstrap (`Common`) und liefen daher nie
durch `Common::setUpdates()` → den `UpdatesLibrary`-Tick (Flottenankunft,
Statistik, Cleanup). `LegacyController` ruft den Tick jetzt vor jeder
promoted-Dispatch selbst auf (der Legacy-Fallthrough führt `Common` weiter
selbst aus → kein Doppellauf).

| Cluster | Module | Status |
|---|---|---|
| Übersicht/Werft | Overview ✅, Shipyard ✅, Defenses ✅ | migriert |
| Galaxie/Phalanx | Galaxy ✅, Phalanx ✅, Movement ✅ | migriert |
| Flotten-Assistent | Fleet1 ✅, Fleet2 ✅, Fleet3 ✅, Fleet4 ✅ | komplett — Commit-Schritt parametrisiert, 10 Validatoren portiert |
| ACS | Federation ✅ | komplett — SQL-Injection in `searchUser` behoben, alle Queries parametrisiert |

✅ **Welle 4 abgeschlossen.** `legacy/app/Http/Controllers/Game/` ist leer — die
gesamte Controller-Schicht ist migriert.

Hinweis: Fleet3 nutzt `HttpResponseException(redirect(...))`, um aus tief
verschachtelten Helfern (Session-Ships fehlen, keine erlaubte Mission,
ungültiges Ziel) sauber nach fleet1 zurückzuspringen — der Legacy-Code machte
das per `header()+exit`, was in Laravel die Session-Speicherung umgangen hätte.

## Welle 5 — Leaf-Bibliotheken ✅ abgeschlossen

Nachdem alle Controller nativ sind, werden die verbleibenden Legacy-Bibliotheken
(`legacy/app/Libraries/`) einzeln zu typisierten `app/`-Klassen migriert. Ein
Kandidat ist ein **echtes Blatt**, wenn keine andere Legacy-Datei ihn referenziert
(auch nicht per Namespace-Kurzreferenz oder `use … as`-Alias) — nur dann ist die
Löschung sauber.

| Bibliothek | Status | Notiz |
|---|---|---|
| BBCodeLib (167 Z.) | ✅ migriert | **`eval()`-Dispatch entfernt** → typisierte Closures pro Tag; XSS-Schemata weiter geblockt; 39 Baseline-Einträge abgebaut |
| Users\Shortcuts (122 Z.) | ✅ migriert | **`die()` bei JSON-/Validierungsfehlern entfernt** (graceful); `getById()`-Typbug (`0` statt `array`) behoben; Einträge auf feste Shape normalisiert → Aufrufer typsicher; 16 Baseline-Einträge abgebaut |
| Game\AcsFleets (61 Z.) | ✅ migriert | toter `getUserId`/User-ID-Param entfernt; `getFirstAcs()` gibt bei leerer Menge eine leere Entity statt Index-out-of-bounds; 10 Baseline-Einträge abgebaut |
| Buddies\Buddy (131 Z.) | ✅ migriert | Anfragen-Aufteilung (bestätigt / gesendet / empfangen) auf `array_filter` mit typisierten Closures; 10 Baseline-Einträge abgebaut |
| Adm\Permissions (27 Z.) | ✅ migriert | **`die()` bei JSON-Fehler entfernt** → leere Deny-all-Matrix; typsicherer Nested-Lookup; 3 Baseline-Einträge abgebaut |
| Premium\Premium (63 Z.) | ✅ migriert | Entity-Wrapper wie AcsFleets/Buddy; toter User-ID-Param entfernt (5 Aufrufer angepasst); `getCurrentPremium()` leer-sicher; 6 Baseline-Einträge |
| Research\Researches (59 Z.) | ✅ migriert | dito; toter User-ID-Param entfernt; `getCurrentResearch()` leer-sicher; 6 Baseline-Einträge |
| Game\ResourceMarket (192 Z.) | ✅ migriert | **dynamische Methoden-Dispatch** (`{'getPlanetAmountOf'.ucfirst($r)}()`) durch typisierte `match`-Helfer ersetzt → 11 Baseline-Einträge weg; tote `UserEntity` entfernt; Division-durch-0-Guard; 5 Charakterisierungs-Tests (Preis-Mathematik gegen Hand-Rechnung verifiziert) |
| Alliance\Alliances (98 Z.) | ✅ migriert | letzter isolierter Leaf; `checkRank`-Nested-Access typsicher; `getCurrentAlliance()` leer-sicher; hält bewusst die Legacy-`Ranks`-Referenz (die auch `Users` nutzt); 5 Tests (Owner/Access/Rank-0/Filter) |
| NoobsProtectionLib (105 Z.) | ✅ migriert | **erstes „App-Klasse, die auch Legacy nutzt"** — 4 native + 1 Legacy-Aufrufer (`GalaxyLib`) umgehängt; `returnPoints`-Query parametrisiert; Property-Typen + keine Mutation mehr; 5 Tests (Weak/Strong/Zeit-Schwelle/Rang) via DB-Settings |
| Game\Fleets (123 Z.) | ✅ migriert | Flotten-Entity-Wrapper (clean leaf, 4 native); **Null-Deref-Bug gefixt**: `getOwnValidFleetById` rief Methoden auf dem null-Ergebnis von `getOwnFleetById` auf; Index/Zähler typisiert; 8 Tests |
| Messenger-Cluster (Messenger, MessagesOptions, MessagesFormat) | ✅ migriert | nur von `Functions.php` (legacy) genutzt → umgehängt. **2 Bugs gefixt:** SQL-Injection in `Messenger::sendMessage` (from/subject/text roh interpoliert) parametrisiert; `MessagesOptions::getType()` gab wegen `is_object()` auf `int` **immer GENERAL** zurück → Nachrichten-Kategorie (Espio/Kampf/…) landete nie in der DB, jetzt respektiert. INSERT auf portables `VALUES` umgestellt. 7 Tests (inkl. DB-End-to-End) |
| Formulas (281 Z., zentrale Formel-Lib) | ✅ migriert | Der native `FormulasService` (bereits vorhanden, DI-basiert) spiegelt die Legacy-Statik vollständig. Die 5 nativen Statik-Aufrufer (TechnologyInfoService, Shipyard-/Galaxy-/Resourcesettings-/Phalanx-Controller) auf **injizierten `FormulasService`** umgestellt; für die noch nicht migrierte Engine bleibt eine schlanke **statische `App\Libraries\Formulas`-Fassade**, die an den Service delegiert (eine Wahrheitsquelle). Legacy-Aufrufer (`UpdatesLibrary`, `GalaxyLib`, `PlanetLib`, `DevelopmentsLib`, `Missions\Destroy`) umgehängt. **Bonus:** `PlanetLib::setNewMoon`-INSERT von roher String-Interpolation auf parametrisiertes `VALUES` umgestellt (Injection-Wart weg). 15 neue Tests (Service-Mathematik + Fassaden-Delegation); 8 Baseline-Einträge abgebaut |
| DevelopmentsLib (298 Z., Kosten/Zeit/Preis) | ✅ migriert | statische Utility (wie Formulas), 2 native (Shipyard, Overview) + 1 Legacy-Aufrufer (`UpdatesLibrary` via Alias `Developments`). Nach `App\Libraries` portiert und **auf Level 9 sauber getippt**: die untypisierten `array`-Spielstands-Maps werden durch mixed-sichere `asInt/asFloat/asString`-Konverter gelesen; `else`/`elseif`/`switch` durch Guards ersetzt. Legacy gelöscht, alle Imports umgehängt. 8 Charakterisierungs-Tests (Seiten-Klassifikation, Lab-/Werft-Status, Zeit-Prefix); 17 PHPStan- + 19 PHPMD-Einträge abgebaut |
| MissionControlLib (129 Z., Tick-Missions-Dispatcher) | ✅ migriert | nur von `UpdatesLibrary` (Tick) genutzt. **Reflektive Dispatch entfernt**: der aus einem String gebaute Klassenname + magische Methode (`$mission->$name($fleet)`) ist durch ein explizites, typgeprüftes `match` (Missions-ID → konkrete Klasse) ersetzt; unbekannte IDs werden übersprungen statt in einen Undefined-Index zu laufen. `time()`-Interpolation parametrisiert. 14 Tests (ID→Handler-Mapping via Reflection). 17 PHPStan- + 1 PHPMD-Eintrag abgebaut |
| PlanetLib (166 Z., Planeten-/Mond-Erzeugung) | ✅ migriert | Instanz-Klasse, 7 native + 2 Legacy-Aufrufer (`Missions\Colonize`, `Missions\Attack`). Nach `App\Libraries` portiert, Parameter/Rückgaben typisiert (`setNewMoon` gab laut PHPDoc `string` zurück, tatsächlich `bool`). **Null-Deref-Bug gefixt**: der Mond-Lookup dereferenzierte `$MoonPlanet['id_moon']` auf einem potenziell `null`-Ergebnis. Alle 3 Roh-`INSERT … SET` (Planet + Buildings/Defenses/Ships) parametrisiert. `setNewMoon` in `buildMoonData`/`createMoon` aufgeteilt (Komplexität < Schwelle). 4 DB-Tests (Erzeugung, kein Überschreiben, Mond-Erzeugung, kein Zweitmond). 3 PHPStan- + 5 PHPMD-Einträge abgebaut |
| FleetsLib (500 Z., Flotten-Präsentation) | ✅ migriert | statische Utility, 6 native + 10 Legacy-Missions-Aufrufer. Der numerische Teil (Speed/Verbrauch/Max-Werte, 11 Methoden) war bereits vom nativen `FleetsService` gedeckt → **als toter Code entfernt**; nur die View-Helfer (Event-Zeilen, Hover-Popups, Koord-Links, (De-)Serialisierung, `hasResources`) portiert. `flyingFleetsTable` in typisierte `match`-Helfer zerlegt statt eines Monster-`switch`. **Härtung:** `getFleetShipsArray` mit `allowed_classes => false` (kein Objekt-Injection via manipulierter `fleet_array`) und auf `array<int,int>` normalisiert — das räumte zugleich eine Kaskade von `mixed`-Arithmetik-Fehlern in der Missions-Engine auf. 5 Tests (Round-Trip, Normalisierung, Objekt-Abwehr, `hasResources`). 20 PHPStan- + 20 PHPMD-Einträge abgebaut |
| GalaxyLib (724 Z., Galaxie-Zeilen-Renderer) | ✅ migriert | **sauberes Blatt** — nur von `GalaxyController` genutzt (kein Legacy-Aufrufer). Nach `App\Libraries` portiert und auf Level 9 typisiert: untypisierte Properties/Params, `mixed`-Array-Zugriff über `asInt/asFloat/asString`. Die duplizierten Missions-Links auf einen `fleetLink`-Helfer (mit `&amp;`-/`&`-Separator-Flag, das ich verifiziert habe) zusammengeführt, Aktions-Reihenfolge bewusst 1:1 zur Legacy-Key-Deklaration erhalten (bestimmt die HTML-Ausgabe-Reihenfolge). Bestehenden `GalaxyLibTest` auf die neue Struktur umgestellt + 2 Separator-Tests. 30 PHPStan- + 25 PHPMD-Einträge abgebaut |
| UpdatesLibrary (927 Z., der Tick) | ✅ migriert | das noch-legacy Herzstück: Cleanup, Backup, Flotten-Missionen, Statistik + die Ressourcen-/Queue-Auffrischung. Nach `App\Libraries`. **Toter Code entfernt:** `checkBuildingQueue` (unused) + `setFirstElement` (0 Aufrufer) = ~295 Z. der komplexesten Logik weg; tote `ProductionService`-DI + `$parse`/`$update`. Die By-Ref-Ressourcen-Mathematik (`updatePlanetResources`/`updateHangarQueue`) auf `int`/`float`/`string`-sichere Coercion-Helfer (`num`/`numInt`/`str`) umgestellt — **Werte bit-genau erhalten** (nur die implizite String-Arithmetik explizit gemacht); Hangar-Timer/-Queue über lokale typisierte Variablen statt In-Place-`mixed`-Mutation. Alle Aufrufer umgehängt (4 native + 4 legacy). Die By-Ref-Typisierung schärfte `$target_planet` in `Missions\Attack`/`Destroy` → 6 latente Engine-Imprecisions dokumentiert baselined (untested Engine nicht angefasst). 18 PHPStan- + 21 PHPMD-Einträge netto abgebaut |
| Users (315 Z., Session-Singleton) | ✅ migriert | zweit-pervasivste Abhängigkeit (25 native + Legacy-Aufrufer). Der zustandsbehaftete `getInstance()`-Singleton (Session-Validierung, aktiver Planet, Ressourcen-/Queue-Tick im Konstruktor) nach `App\Libraries`. Properties/Rückgaben/Params typisiert, `mixed`-Zugriff über `asInt/asString`. **Sicherheit:** alle Session-abgeleiteten SQL-Werte (`session('user_id')`, `$_SERVER`, ally-/planet-IDs) von String-Interpolation auf **gebundene Parameter** umgestellt (Injection-Härtung); die Login-/Auth-Integritäts-Checks in `displayLoginErrors` 1:1 im Verhalten erhalten. `else` in der Allianz-Übergabe zu Guards + `handleAllianceOnDelete`/`findRightHandRank`-Helfern; `isset && !is_null` (immer-wahr) bereinigt. Alle Aufrufer umgehängt (inkl. `new Users()` in `UpdatesLibrary`). 3 Tests. 13 PHPStan- + 3 PHPMD-Einträge abgebaut |
| Functions (246 Z., Utility-Grab-Bag) | ✅ migriert | **die pervasivste Legacy-Abhängigkeit** — von **42 nativen Dateien** + 14 Legacy-Dateien statisch genutzt (jeder Controller importierte sie). Nach `App\Libraries`, alle 5 PHPStan-Typlücken geschlossen (`$center: bool`, `getLanguagesList`/`isCurrentPlanet` value-types, `session('locale')`-mixed für `App::setLocale` abgesichert). `else`/`switch` durch Guards bzw. ein typisiertes `messageType`-`match` ersetzt, auto-vivifiziertes `$parse` weg; `exit`/Bool-Flags als Legacy-Flow bewusst per `@SuppressWarnings` behalten. Alle 56 Aufrufer umgehängt (inkl. 2 Same-Namespace-Legacy-Dateien). 6 Tests. 5 PHPStan- + 4 PHPMD-Einträge abgebaut |
| SecurePageLib (45 Z., Legacy-Input-Sanitizer) | ✅ migriert | nur von `Core\Common` (Legacy-Bootstrap) genutzt. Nach `App\Libraries`, `$instance`/`validate` typisiert, `else` durch Guard ersetzt. **Bugfix:** die rekursive Array-Sanitisierung nummerierte verschachtelte Arrays neu (`$value[$c]`) → verschachtelte Formular-Eingaben wurden still korrumpiert; jetzt bleiben die Keys erhalten. Non-Scalar-Blätter werden zu `''`. 3 Tests. 3 PHPStan- + 3 PHPMD-Einträge abgebaut |
| StatisticsLibrary (670 Z., Punkte/Ränge-Batch) | ✅ migriert | war bereits gut typisiert (nur 2 PHPStan-Einträge); 5 native + 3 Legacy-Aufrufer (`UpdatesLibrary`, `Missions\Missions`, `Missions\Colonize`). Nach `App\Libraries` portiert: `$time`/`makeStats`-Rückgabe getippt (präzise Shape → Consumer bleibt typsicher), `calculatePoints` akzeptiert `int|string` (ResearchQueue gibt String), Div-durch-0-Guard, `self::`→`$this->`, auto-vivifiziertes `$rank`/`$result` initialisiert, toter `$update` entfernt. Die zwei großen Ranking-Batches (`makeUserRank`/`makeAllyRank`) 1:1 erhalten, Komplexität per `@SuppressWarnings` statt Baseline. Bestehender `StatisticsLibraryTest` umgehängt. 2 PHPStan- + 11 PHPMD-Einträge abgebaut (+ 2 veraltete Aufrufer-Einträge) |
| Alliance\Ranks (159 Z., Allianz-Ränge) | ✅ migriert | nach `App\Libraries\Alliance\Ranks` (final, präzise Shape `array{rank: string, rights: array<int, int>}` pro Rang-Id), 4 native Aufrufer umgehängt und ihre Defensiv-Checks entfernt. **`die()` entfernt** (kaputtes JSON → keine Ränge, leerer Name → nichts angelegt). **Bugs gefixt:** eine negative Rang-Id wurde auf 0 geklemmt und löschte per `deleteRankById` den **Gründer-Rang** (im Spiel per manipuliertem `d=-1` erreichbar); `editRankById` mit Id > Anzahl prüfte die geklemmte Id, schrieb aber die ungeklemmte und legte so einen Rang ohne Namen an; Rang-Namen wurden SQL-escaped gespeichert, obwohl sie gebunden geschrieben werden (`O'Neil` erschien als `O\'Neil`). Unbekannte Ids werden jetzt ignoriert. 9 Tests; 14 PHPStan- + 10 PHPMD-Einträge abgebaut. Das Nachziehen von `users.ally_rank_id` beim Löschen eines Rangs ist später behoben (`AllianceRankService`, siehe unten) |
| Combatreport\Report, Entity\{Report,Notes,Preferences,Ships,User}Entity, ReportStatusEnumerator, Helpers\ArraysHelper, `resources/views/legacy_NotInUse/` | 🗑️ gelöscht (tot) | 0 Referenzen (der Kampfbericht läuft nativ über `CombatreportController`; die Views wurden nur in auskommentiertem Code erwähnt, der mit entfernt ist). 22 PHPStan- + 9 PHPMD-Einträge abgebaut |
| Core\Objects (deprecated Objekt-Konfig) | ✅ getippt | die überladenen Getter (`getObjects($id = null)` usw.) behalten ihre API, liefern aber über **bedingte Rückgabetypen** präzise Typen (`($objectId is int ? string : array<int, string>)`); Properties mit Shapes (`CombatSpec`, `ProductionSpec` inkl. Formel-Closures). Collections über `all()` statt `toArray()`. In 11 Aufrufern (Fleet1–4, Galaxy, Movement, Overview, Premium, Resourcesettings, Shipyard, Missile) fallen die `is_array`/`is_string`/`is_numeric`-Schutzabfragen weg. `UpdatesLibrary` normalisiert die Formel-Argumente vor dem Aufruf. 12 PHPStan- + 1 PHPMD-Eintrag abgebaut |
| Core\Template (Blade-Helfer) | ✅ migriert | nach `App\Libraries\Template` (4 native Aufrufer). `legacyView()` ist `never`, `render()` liefert immer `string`. **Bug gefixt:** bei fehlender View kam `null` zurück, `jsReady(Template::render(...))` (FleetsLib-Popup) warf dann einen `TypeError`. `LegacyView` nimmt jetzt das View-Interface (`View::make()` liefert dieses). 3 Tests; 6 PHPStan- + 2 PHPMD-Einträge abgebaut |
| Core\Entity + 8 Entities | ✅ getippt | `Entity` normalisiert die DB-Zeile auf `array<string, int\|float\|string\|bool\|null>` (Alias `Column`); die Unterklassen-Konstruktoren riefen nur `parent::__construct()` auf und sind weg. Die Roh-Getter deklarierten `@return string`, lieferten aber den DB-Wert (meist `int`); jetzt ehrlich `Column`. 11 PHPStan-Einträge abgebaut |
| Enumeratoren + PreparesLegacySql | ✅ | die kleingeschriebenen Konstanten (`SwitchInt::on`, `Ships::ship_deathstar`, `BuddiesStatus::isBuddy` …) heißen jetzt UPPER_CASE (167 Stellen, keine dynamischen Zugriffe). **Damit hat `legacy/` keinen einzigen Baseline-Eintrag mehr und ist PHPMD-roh verletzungsfrei** |
| Users\Notes, Game\Preferences, Planet\Ships | 🗑️ gelöscht (tot) | 0 Referenzen (Ships-„Aufrufer" im Scan waren False-Matches auf den `ShipsEnumerator as Ships`-Alias); Notes/Preferences durch Eloquent-Modelle ersetzt; latente Bugs (null statt Entity, `[0]` auf leerer Menge) mit-entfernt |
| Buildings/ (Building, Queue, QueueElements) + QueueTest | 🗑️ gelöscht (tot) | String-basierte Alt-Bau-Queue, in Produktion durch `BuildingQueue`-Modell + `BuildingQueueService` ersetzt (0 Referenzen). `QueueTest` testete nur die tote String-Logik; die moderne Sequenzierung deckt bereits `QueueSequenceServiceTest` ab — kein Verlust an Live-Coverage. 29 Baseline-Einträge abgebaut |
| _Nachtrag:_ `BuildingQueueService` | ✅ getestet | Der dokumentierte Test-Gap ist geschlossen: neue **DB-Test-Infrastruktur** (In-Memory-SQLite + Install-Migrationen via `DatabaseTestCase`) + 6 Charakterisierungs-Tests (add/Charge, Positions-Increment, Queue-Cap, cancelFirst/Refund, getQueueData) |

Reihenfolge nach Sauberkeit (Blatt-Kandidaten mit den wenigsten Legacy-Referenzen
zuerst). Für Libs, die die noch lebende Engine mitbenutzt (`Formulas`,
`NoobsProtectionLib`), gilt das **Fassaden-Muster**: die Logik zieht nativ um, eine
dünne `App\Libraries\…`-Brücke bedient die Engine weiter, bis auch diese migriert
ist.

---

## Welle 6 — Missions-/BattleEngine (OPBE) ✅ abgeschlossen

Die vendorte GPL-Kampf-Engine (OPBE, Jstar 2013) und die Missions-Handler sind
tief verkettet (Vererbung, gegenseitige Referenzen) und **ungetestet**. Auf
Wunsch: **„Alles sauber typisieren"** — die gesamte Engine auf Level-9-clean
bringen (Baseline → ~0), **in-place** im bestehenden `Xgp\App\…\BattleEngine`-
Namespace (die Massen-Umbenennung nach `App\` erst am Ende, wenn alles typsicher
ist). Vorgehen **bottom-up**: erst die Blatt-Utilities, dann die Datenobjekte,
dann die Sammlungen, dann Core/Missions.

**Merkposten (engine-spezifisch):** Die Docblocks deklarieren durchweg `@return int`
/ `@param int`, obwohl die Engine intern mit `float` rechnet (Wahrscheinlichkeiten,
Schaden, Schilde). Wo ein Zähl-/Kontrakt-Typ (`PhysicShot`-Schüsse,
`ShipsCleaner`) echt `int` verlangt, wird an der Methodengrenze mit `(int)`
**eingehegt** statt die Kaskade weiterzureichen; wo der Wert real `float` ist,
wird der falsche `@return int` korrigiert.

| Baustein | Status | Notiz |
|---|---|---|
| Utils (Number, Gauss, Events, GeometricDistribution, Math) + Models (Defense, Ship) | ✅ getippt | Blatt-Schicht: `int|float`-Rechenwerte, `getRepairProb(): float`, Gauss/Verteilungen `float`, `Math::divide/multiple(): Number`, `heaviside/rest` mit `(int)$x % (int)$y`; redundante `is_callable`-/`is_numeric`-Guards weg |
| Models\Type + Models\ShipType | ✅ getippt | die Datenobjekte, die die Sammlungen halten. `Type`: `int $id` / `int|float $count`, typisierte Accessors, `__toString(): string` (`ob_get_clean()`-`string|false` gecastet), `cloneMe(): self`. `ShipType extends Type` (26 Einträge): alle 15 Properties getippt (Akkumulatoren `full*`/`current*` mit `= 0`-Default, da `increment()` sie **im Konstruktor vor der Zuweisung** per `+=` nutzt), `singleLife` als reines `float` (PHPStan: nie `int`), Tech-Setter `?int` (Null-Pfad echt erreichbar → `is_numeric` nicht mehr „always true"), `inflictDamage(): ?PhysicShot`, Schuss-Zähler an `PhysicShot`/`ShipsCleaner` mit `(int)` eingehegt. Cascade in `Fire::getNormalPower()` (Leistung ist real `float`) mit-korrigiert. 41 Baseline-Einträge abgebaut |
| CombatObject\PhysicShot + ShipsCleaner | ✅ getippt | die beiden Blätter, die `ShipType` direkt konstruiert. `PhysicShot`: Properties getippt (`$fighters: ShipType`, Schadens-Akkumulatoren `float`), **tote Property `$cellDestroyed`** (nur geschrieben, nie gelesen) entfernt, `getPureDamage()` real `int|float`, `getHitShips()` per `(int)` ein Zähler, `clamp()` von `mixed` auf `int|float`. `ShipsCleaner`: `$exploded: int`/`$remainLife: float`. Konstantengetriebene „always true"-Hinweise bleiben baselined. 11 Einträge abgebaut |
| CombatObject\Fire (Rest) | ✅ getippt | Properties `$shots`/`$power` als `int|float`, alle `getShotsFiredBy*` geben `Number` zurück (`bool $real`), `getAttackerTotalFire/Shots()` von falschem `@return int` korrigiert, `__toString(): string`, `cloneMe(): self`. 15 Einträge abgebaut; drei `mixed`-Kaskaden aus `Fleet::getIterator()` bleiben bewusst baselined bis der Sammlungscluster den `getIterator()`-Rückgabetyp schärft |
| IterableUtil-Sammlungscluster (Fleet, Player, PlayerGroup, FireManager, HomeFleet) | ✅ getippt | **das Kernstück** — mussten zusammen getippt werden. `IterableUtil` wird **generisch** (`@template TValue`, `@var array<int, TValue> $array`); die Subklassen binden das Element via `@extends IterableUtil<ShipType|Fleet|Player|Fire>` → alle `$this->array`-Element-Zugriffe werden typsicher **ohne** Per-Methoden-Casts. Alle Properties/Methoden/Params getippt (id/tech/coords als `int`/`?int`, `count` als `int|float`, `__toString(): string`). **Bug gefixt:** `Fleet::cloneMe()` übergab `$this->galaxy` an der `$name`-Position → Flotten-Name ging verloren und alle Koordinaten waren um eins verschoben (nur Report-Anzeige betroffen, nicht die Kampf-Mathematik). `PlayerGroup::$battleResult` als `?int` (BATTLE_WIN/LOSE/DRAW). 158 Einträge abgebaut. Bewusst weiter baselined: die wenigen `getIterator()`-`mixed`-Ableitungen (bis `Core`/`Fire` getippt sind) und die Aufrufer-Kaskaden im noch untypisierten Missions-/Core-Layer (mixed DB-Zeilen an die jetzt typisierten Methoden) — lösen sich beim Typisieren jener Dateien auf |

| Core (Battle, Round, BattleReport) + `getIterator()`-Flip | ✅ getippt | die Verbraucher des Sammlungs-Kerns. `Battle`/`Round` mit typisierten Properties/Rückgaben; `setBattleResult(?int)`. `BattleReport` (59 Einträge) vollständig: die verschachtelte Verlust-Struktur als präzise Shape `array<int, array<int, array<string, array<int, array{0: int|float, 1: int|float}>>>>` → die Debris-Iteration ist typsicher. Danach **`IterableUtil::getIterator(): array<int, TValue>`** geschärft — das löst die letzten `getIterator()`-`mixed`-Kaskaden in Fire/Round/Fleet/Player **und** die Schleifen im (noch untypisierten) Missions-Layer auf. **Bugs gefixt:** zwei doppelte `getIterator()`-Aufrufe (`getAttackersTech`/`getDefendersTech` riefen die Methode auf dem bereits entpackten Array auf → Fatal), `getPlayer()`-nach-`existPlayer()` auf `Player\|false` sauber ge-null-checkt statt doppelt abgefragt. Tote Properties `$attackersLostUnits`/`$defendersLostUnits` entfernt. **Basis-`ShipType::getRepairProb(): float`** ergänzt (Ship/Defense überschreiben), damit der polymorphe Aufruf auf der `ShipType`-getippten Sammlung gültig ist. Netto ~90 PHPStan-Einträge abgebaut. Verbleibend baselined: konstantengetriebene Logik-Hinweise + die Missions-internen Kaskaden (bis die Handler getippt sind) |

| Utils (DebugManager, Functions, LangManager) | ✅ getippt | die letzten Engine-Blätter. `DebugManager`: Error-/Exception-Handler typisiert (`myErrorHandler(int,string,string,int): bool`, `save(mixed): void`), tote Properties entfernt, `var_export(…, true)` (Bug: fehlender 2. Parameter gab `null`), `@SuppressWarnings(Superglobals)`. `Functions` (globale `log_var`/`log_comment`): `mixed`-sicher über `print_r(…, true)`. `LangManager`: Singleton + `__call` typisiert, der dynamische `[$impl, $name]`-Callable per `is_callable`-Guard abgesichert. 32 Einträge abgebaut |

Damit ist die **BattleEngine (OPBE) vollständig Level-9-sauber typisiert** — es
bleiben nur konstantengetriebene Logik-Hinweise und ein paar genuin
`mixed`-Aggregate baselined. Die Missions-Handler sind inzwischen ebenfalls
getippt (siehe unten), die temporären Aufrufer-Baseline-Einträge sind damit
aufgelöst.

### Sicherheitsnetz vor den Missions-Handlern: Charakterisierungs-Tests

Bevor die (ungetesteten, DB-lastigen) Kampf-Handler `Attack`/`Destroy` angefasst
werden, nageln Charakterisierungs-Tests ihr aktuelles Verhalten fest
(`tests/Unit/Legacy/App/Libraries/Missions/`, gemeinsame Basis
`MissionLootCharacterizationTestCase`). Da der Kampf selbst zufallsbasiert ist,
zielen sie auf die **deterministischen, typ-sensiblen Kerne**: die
Beute-Arithmetik `plunder()` (Golden Values aus der Ist-Implementierung,
inkl. Bruch-Beträgen) und die Ship/Defense-Fabrik `getShipType()` gegen die
echte Objekt-Konfiguration (ohne DB).

**Gleich beim ersten Lauf fanden sie eine echte Regression** aus der
`ShipType`-Typisierung dieser Welle: `getShipType()` übergab Einheiten ohne
Rapid-Fire-Tabelle (8 von 9 Verteidigungsanlagen + 502/503) eine `int 0` statt
eines Arrays — seit `ShipType::__construct(array $rf)` warf jede Attack-/
Destroy-Mission gegen einen verteidigten Planeten einen `TypeError`. PHPStan sah
es nicht, weil der Aufrufer-Fehler unter der alten Missions-Baseline lag.
Gefixt (`?? []`, verhaltensgleich: `getRfTo()` liefert weiter 0) und per
eigenem Regressionstest abgesichert.

### Missions-Basis (`Missions.php`) ✅ getippt

Die 74 Einträge hatten fast alle eine Ursache: Werte aus `mixed`-DB-Zeilen
wurden per String-Konkatenation ins rohe SQL gespleißt. Neu:

- **Gebundene Parameter statt Interpolation** über den Trait
  `Xgp\App\Core\Concerns\BindsLegacyQueries` (`statement`/`selectRow`/
  `selectRows`). Jeder Wert wird dabei als exakt der String gerendert, den die
  Interpolation erzeugt hat, damit die SQL-Semantik gleich bleibt. Escapen tut
  jetzt der Treiber. Bei `insertReport` war der Bericht-Inhalt bisher per
  `addslashes()` in den Aufrufern vorescaped, das wurde mit entfernt, sonst
  hätte das Binding Backslashes doppelt gespeichert.
- **Präzise Array-Shapes** für alle `$data`-Parameter (Aliase `Coords3`/
  `Coords4`/`Resources`/`Row`), mit `mixed`-Blättern, damit die noch
  untypisierten Handler nicht kaskadieren. Die toten `is_array()`-Guards und
  `= []`-Defaults sind entfernt (kein Aufrufer nutzte sie).
- `resource`/`pricelist`/`combat_caps` sind typisiert und werden über
  validierende Konverter gefüllt (gegen die echte Konfiguration verifiziert:
  100 % konform, also verhaltensneutral).
- **Bug gefixt:** `updateUserCurrentPlanetByCoordsAndUserId()` las
  `coords['fleet_end_galaxy']`, der einzige Aufrufer (Destroy) liefert aber
  `coords['galaxy']`. Nach einer Mondzerstörung warf das eine `ErrorException`,
  statt den aktuellen Planeten des Users vom zerstörten Mond wegzuschalten.

Die Handler-Einträge haben dadurch nur die Form gewechselt (von „offset on
mixed“ zu „mixed given“). Sie wurden geprüft (keine konkreten Typ-Konflikte)
und neu baselined. Sie verschwinden mit der Handler-Typisierung.

### Missions-Handler

| Handler | Status | Notiz |
|---|---|---|
| Acs, AttackLang, Deploy, Stay, Transport | ✅ getippt | `array<string, mixed>`-Zeilen, Werte an typisierten Grenzen (`sendMessage`, `prettyNumber`, `returnFleet`, …) über die Trait-Helfer `int`/`num`/`str` coerced (für DB-Werte verhaltensgleich; die Sprachstrings nutzen ausschließlich `%s`). camelCase-Parameter/Locals, `StaticAccess` per Klassen-Annotation (Konvention der Handler). **Anzeige-Bug gefixt (Stay):** `stay_mess_user` hat in beiden Sprachen 5 Platzhalter (Herkunftsplanet, Link, 3 Ressourcen). Der Code übergab zusätzlich Zielname/-link, der Empfänger sah deshalb den Zielplaneten an der Stelle der Metall-/Kristallmengen. 19 PHPStan- + 13 PHPMD-Einträge abgebaut, alle fünf Dateien PHPMD-roh verletzungsfrei |
| Colonize | ✅ getippt | **Crash gefixt:** `buildNewFleet()` war `: bool` deklariert, gab aber den serialisierten Flotten-String zurück. Unter `strict_types` warf das einen `TypeError`, und zwar bei **jeder Kolonisierung mit mehr als dem Kolonieschiff**. Der Fehler stand wörtlich in der Baseline („should return bool but returns string“). Die if/elseif/else-Kaskade wurde in `tryToColonize()` mit Guard-Clauses gezogen, Reihenfolge und Seiteneffekte bleiben identisch. `positionAllowed()` ist ohne `else` umgebaut und per Tabellen-Test gegen die **Original-Implementierung** verifiziert. Tests: `buildNewFleet` (Regression) + `positionAllowed` (9 Fälle). 7 PHPStan- + 5 PHPMD-Einträge abgebaut |
| Recycle | ✅ getippt | Die verschachtelte if/else-Kaskade zur Trümmerfeld-Aufteilung ist als reine `splitRecycledDebris()` herausgezogen (Guard-Clauses + `min()`, bei Gleichstand wie das Original das erste Argument) und gegen **Golden Values des Original-Algorithmus** aus 7 Fällen charakterisiert, inklusive Bruchwerten und dem praktisch unerreichbaren Fall negativer Kapazität, der bewusst *nicht* verändert wurde. Properties `planetDebris`/`recyclersCapacity` getippt, Coercions an den typisierten Grenzen. 7 PHPStan- + 8 PHPMD-Einträge abgebaut |
| Missile | ✅ getippt | Die Schadensschleife ist als `impactDefenses()` herausgezogen (zerstörte Einheiten + SQL-Fragment) und gegen **Golden Values des Original-Loops** charakterisiert (5 Fälle mit echter Objekt-Konfiguration: Komplettzerstörung, Teilschaden mit `floor`, Schildtechnik, Primärziel-Reihenfolge, Nullschaden). Die Methode ist in `penetrateDefenses`/`defenseReport`/`sendResults` zerlegt (PHPMD-Komplexität). `__()` mit dynamischem Schlüssel liefert `string\|array`, das fängt `text()` ab. 12 PHPStan- + 9 PHPMD-Einträge abgebaut. **Spiel-Logik korrigiert (nach Entscheidung):** Wurden *alle* Raketen abgefangen, blieben die Abfangraketen bisher auf dem Planeten. Ein Vorrat davon hielt so beliebig viele Angriffe auf. Der Legacy-Code berechnete dafür ein `$amount`, nutzte es aber nie. Jetzt verbraucht `interceptAll()` eine Abfangrakete pro Rakete (Regressionstest) |
| Spy | ✅ getippt | `spyMission` zerlegt (`spy`, `espionageLevel`, `spyWithProbes`, `reportDepth`, `generateSpyReport`, `resourcesReport`). Die HTML-Berichte sind gegen eine **Golden-Fixture des Originals** (`tests/Fixtures/spy_reports.json`) für alle fünf Berichtsarten **byte-identisch** geprüft. Die Wahl des Berichtsumfangs (`reportDepth`) ist über ein Raster aus 65 Kombinationen von Level-Differenz × Sonden gegen eine wörtliche Kopie der Original-Logik verifiziert. RNG-Reihenfolge (`mt_rand`) unverändert. 11 PHPStan- + 10 PHPMD-Einträge abgebaut. **Spiel-Logik korrigiert (nach Entscheidung):** Der Original-Code überschrieb die Spionage-Level direkt vor dem Vergleich fest mit 100/0. Der Spion galt dadurch immer als überlegen, höhere gegnerische Spionagetechnik schützte nicht vor detaillierten Berichten. `reportDepth()` vergleicht jetzt die echten Level: Ist das Ziel gleich stark oder stärker, steigen die Sonden-Schwellen um das Quadrat der Differenz, sonst sinken sie. Geprüft über ein Raster aus 5×5 Leveln × 31 Sondenzahlen gegen die Original-Regeln mit echten Leveln |
| Expedition | ✅ getippt | **Crash gefixt:** `resultEarly()` berechnete die neue Rückkehrzeit als `end_time - returnTime / 2`. Bei ungerader Rückflugzeit ist das ein Float, und `updateFleetEndTime(int)` warf unter `strict_types` einen `TypeError` (in etwa jedem zweiten „frühe Rückkehr“-Ergebnis). Jetzt `intdiv()` in `earlyReturnEndTime()`, mit Regressionstest. Das `switch` ist ein `match`, die leeren Stubs `resultPirates`/`resultAliens`/`resultMerchant` sind entfernt (sie endeten schon vorher wie „nothing“). Die sieben duplizierten Nachrichten-Aufrufe laufen über `expeditionMessage($fleet, $message)`. Die Verlustberechnung im Schwarzen Loch ist als `blackHoleLosses()` herausgezogen und für alle drei Teilverlust-Quoten gegen eine **wörtliche Kopie des Original-Loops** geprüft. 16 PHPStan- + 8 PHPMD-Einträge abgebaut. **Spiel-Logik korrigiert (nach Entscheidung):** 1) Verlor beim Teilverlust *kein* Schiffstyp eine Einheit (z. B. 50 Kleine Transporter bei 1 %), wurde die **ganze Flotte** zerstört. Jetzt passiert nichts (Ergebnis wie „nothing“). 2) Schiffstypen ohne Verlust fielen aus der Restflotte heraus und verschwanden. Jetzt verlassen nur die verlorenen Schiffe die Flotte. Der Teilverlust hat einen eigenen Text `exp_lost_partial` (en/es) mit der Liste der verlorenen Schiffe, statt des Totalverlust-Funkspruchs. `updateFleetArrayById()` schreibt jetzt auch `fleet_amount` (vorher blieb die Stückzahl nach Fund oder Verlust veraltet). **Anzeige-Bugs gefixt:** Die „frühe Rückkehr“ nutzte die nicht existierenden Schlüssel `exp_early_1..5` (der Spieler sah den Rohschlüssel), jetzt `exp_faster_1/2`. Die Texte für Dunkle Materie und Ressourcen zeigten ein unausgefülltes `%s`, jetzt mit Menge und Ressource |
| Attack + Destroy | ✅ getippt | Die beiden Handler waren zu ~90 % Kopien voneinander. Der gemeinsame Kampf-Ablauf steckt jetzt in der abstrakten `CombatMission` (Kräfte aufbauen, Kampf, Überlebende/Beute/Planetenverluste/Punkte/Trümmer schreiben, Bericht), mit den Traits `BuildsCombatForces` (Spieler/Flotten/Einheiten aus DB-Zeilen) und `SendsCombatReports` sowie dem Helfer `CombatSurvivors`. `Attack`/`Destroy` enthalten nur noch ihre Unterschiede über Hooks (`afterBattle`, `onAttackerVictory`, `survivorsReturn`, `reportTitle`, `reportAppendix`). **Charakterisierung vor dem Umbau:** 8 Kampf-Szenarien (Sieg, Niederlage, ACS mit stationierter Fremdflotte, leerer Planet, 4× Mondzerstörung) mit festem Seed gegen den **Original-Code** gefahren und als Golden-Fixture (`tests/Fixtures/combat_settlement.json`) festgehalten: aufgebaute Kräfte, Bericht-Hash, Beute, **jeder DB-Schreibaufruf mit Argumenten**, Zerstörungs-Zustand, Bericht-Anhang und Link. Der umgebaute Code liefert identische Werte (Mutationsprobe: der Test schlägt an). **Crash gefixt (Attack):** Wenn aus den Trümmern ein Mond entstehen sollte, las der Code `planet_id` aus der *leeren* „noch kein Mond“-Zeile (`ErrorException`). Der Kampf war zu diesem Zeitpunkt schon gespeichert, aber Bericht und Mond fehlten. Regressionstest gegen die echte DB (`CombatMoonTest`). **Crash gefixt (Destroy):** Gewannen die Angreifer, aber kein Todesstern überlebte, wurde die Todesstern-Anzahl aus einem fehlenden Schlüssel gelesen (`ErrorException`). Ohne Todesstern gibt es jetzt keinen Zerstörungsversuch (vorher war der Ausgang undefiniert, da Absturz). `getCapacity()` war `: int` deklariert, liefert aber bei Bruch-Stückzahlen `float` (`TypeError`-Risiko), jetzt `int\|float`. Toter Code entfernt: die `planet_id == $playerId`-Zweige in der Spielergruppe (Benutzerzeilen haben keine `planet_id`, die Bedingung war nie wahr) und `extract($moon)`. Reihenfolge: bei Destroy laufen die Folge-Updates nach einer Mondzerstörung jetzt vor dem Speichern des Berichts statt danach (reine DB-Schreibvorgänge ohne gegenseitige Abhängigkeit). 108 PHPStan- + 47 PHPMD-Einträge abgebaut. **Spiel-Logik korrigiert (nach Entscheidung):** 1) Wurden bei Destroy die Todessterne zerstört, wurde die **ganze angreifende Flotte** gelöscht, ohne die Schiffe als Verlust zu zählen. Jetzt gehen nur die Todessterne verloren (wie es auch der Spieltext `des_result_ds` beschreibt) und werden als Verlust gebucht. Der Rest fliegt mit seinem eigenen Beuteanteil heim, der Anteil der Todessterne bleibt auf dem Planeten. 2) Die Überlebenden *aller* verteidigenden Flotten, auch stationierter Fremdflotten, wurden in die Schiffs-Spalten des Planeten geschrieben, während die stationierten Flotten alle Schiffe behielten. Jetzt bekommt der Planet nur seine eigenen Schiffe und Verteidigungsanlagen, stationierte Flotten werden aktualisiert (`updateFleetShipsById()`, ohne ihren Auftrag zu ändern) bzw. bei Totalverlust gelöscht. Dafür bleiben die Planetenkräfte im Kampf eine eigene Flotte (Id 0): `addDefense()` hätte sie mit einer stationierten Flotte des Verteidigers verschmolzen (genau dafür war der tote `Fleet(-1)`-Platzhalter des Originals gedacht). 3) Die Mondgröße aus dem Kampf wurde ignoriert (zufällig 2000–6000). Jetzt bekommt der Mond den im Kampf gewürfelten Durchmesser (mehr Trümmer, größerer Mond). Er startet weiter mit 1 Feld, das `fields` des Kampf-Events ist die Planeten-Formel und gilt nicht für Monde. In der Golden-Fixture sind dafür drei Szenarien bewusst neu gesetzt (ACS mit stationierter Flotte, zwei Todesstern-Szenarien), die anderen fünf sind unverändert |
| Missions-Basis (Rest) | ✅ | `storeResources()` wurde nie mit `true` aufgerufen, das Bool-Flag entfällt. `restoreFleet($row, $start)` ist geteilt in `restoreFleet()` (Startplanet) und `restoreFleetAtTarget()` (nur Deploy, inkl. halber Treibstoff-Rückgabe). `$combat_caps` heißt `$combatCaps`. Die Konfig-Konverter liegen in `MissionObjects` (die Klasse war knapp über dem `ExcessiveClassLength`-Limit). Damit ist **das gesamte `Missions/`-Verzeichnis PHPMD-roh verletzungsfrei** und ohne PHPStan-Baseline-Einträge |

### BattleEngine-Abschluss: Baseline auf 0

Sicherheitsnetz zuerst: `BattleOutcomeTest` fährt 11 komplette, geseedete
Kämpfe (Rapid Fire, Unentschieden, mehrere Spieler je Seite, Todessterne,
Verteidigungs-Reparatur, große Stapel, Sonde gegen leeren Planeten) und
vergleicht gegen eine vor dem Umbau aufgenommene Fixture: kompletter
Kampfbericht, Sieger, Runden, Verluste mit/ohne Reparatur, Trümmer,
Mondchance, Techs, Überlebende, Reparierte, Ids **und die nächste
Zufallszahl** (die Engine muss Zufallszahlen exakt wie vorher verbrauchen).
Alle Werte sind nach dem Umbau identisch.

- **Debug-Ballast entfernt:** `log_var()`/`log_comment()` (OPBEs
  `Utils/Functions.php`) und `echo`-Zeilen in `Round`/`Player`/`PlayerGroup`
  schrieben in einen Ausgabepuffer, den `startBattle()` danach verwarf. Das
  war reine Rechenzeit (u. a. `print_r` aller Flotten pro Kampf). Puffer und
  `$debug`-Flag sind mit entfernt. Ein Test stellt sicher, dass ein Kampf
  keine Ausgabe erzeugt.
- **`DebugManager` entfernt:** `runDebugged()` ersetzte für die Dauer des
  Kampfs den Laravel-Error-Handler. Jede PHP-Warnung im Kampf schrieb eine
  Dump-Datei nach `BattleEngine/errors/` (mit `mkdir 0777` im Quellbaum)
  und beendete den Request per `die()`. Jetzt greift Laravels normales
  Fehler-Handling (Exception + Log).
- **Immer aktive Schalter inline:** `USE_RF`, `USE_RANDOMIC_RF`,
  `USE_HITSHIP_LIMITATION`, `USE_EXPLODED_LIMITATION`,
  `USE_BIEXPLOSION_SYSTEM`, `ONLY_FIRST_AND_LAST_ROUND`, `REPAIRED_DO_DEBRIS`
  waren fest gesetzte Konstanten („do not edit“); die toten Zweige (Quelle der
  „always true/false“-Meldungen) sind weg, ebenso auskommentierter Alt-Code.
- **Toter Code entfernt:** vier der fünf `Fire::getShotsFired*`-Methoden,
  `Math::divide/rest/heaviside/tryEvent`, `Player::addDefense/
  mergePlayerFleets/getOrderedItereator`, `PlayerGroup::createPlayerIfNotExist`,
  `Fleet::getTypeCount`, `Fire::cloneMe`, `BattleReport::$css`.
- **Struktur:** Die Verlust-/Reparatur-/Trümmerrechnung ist aus
  `BattleReport` (Komplexität 84) in `BattleLosses` gezogen; doppelte
  Angreifer/Verteidiger-Varianten teilen sich je eine Implementierung. Das
  View-Rendering der sechs `__toString()` läuft über den Trait `RendersView`
  (mit `finally`, damit ein Fehler keinen offenen Ausgabepuffer hinterlässt).
  `ShipType` fasst die drei Tech-Level in einem Array zusammen und lagert die
  Rundungsfehler-Korrektur aus (NPath). Die Physic-Shot-/Ships-Cleaner-
  Strukturen sind bis in `Round` präzise getippt, `BattleReport::$steal` ist
  ein Array (vorher `0`, ein Rendern ohne `setSteal()` crashte), `tryMoon()`
  würfelt direkt.
- Bool-Flags/Namen: `getDefendersLostUnits(false)` heißt
  `getDefendersDestroyedUnits()`, `Events::event_moon` → `eventMoon`,
  `Math::recursive_sum` → `BattleLosses::total`, snake_case-Properties
  camelCase, kurze Variablennamen ausgeschrieben.

11 PHPStan- + 68 PHPMD-Einträge abgebaut. **Damit sind `Missions/` und
`BattleEngine/` vollständig ohne Baseline-Einträge.**

**Anzeige-Bug gefixt (Kampfbericht):** Der Bericht meldete „…Mond im Orbit
des Planeten Planet [1:1:1]“, sobald *kein* Mond gewürfelt worden war
(`null !== false`), also auch in **jedem** Mondzerstörungs-Bericht. Und wenn
ein Mond entstand, standen Name und Koordinaten fest auf
„Planet [1:1:1]“. Jetzt ist „kein Mond“ der Ausgangszustand, und Attack setzt
das Ziel per `BattleReport::setMoonTarget()`. Die beiden Fixtures haben dafür
nur neue Bericht-Hashes (geprüft: der Berichtstext verliert genau diese eine
Zeile), plus Regressionstest.

> **Lehre aus dem Baseline-Audit:** Die Baseline enthält nicht nur Typ-Unschärfen,
> sondern auch **echte Laufzeit-Crashes**: `strict_types` macht falsche Return-
> oder Argumenttypen an *nativ* getypten Grenzen zu `TypeError`s. Beim Abbau gilt
> deshalb: jede `return.type`/`argument.type`-Meldung mit konkretem (nicht-`mixed`)
> Typ gegen eine *native* Signatur ist als potenzieller Crash zu behandeln. Außerhalb
> der Baseline fand das Audit zudem einen Crash, den PHPStan gar nicht sehen
> konnte, weil ein Docblock falsch war: den Login mit Ban-Eintrag, eigener Fix-Commit.

> **Tooling-Fallen (lokal):** 1) PHPMD lädt `phpmd.baseline.xml` aus dem
> Projekt **automatisch**, auch ohne `--baseline-file`. Echte Rohwerte bekommt
> man nur mit einer explizit übergebenen *leeren* Baseline-Datei. 2) Der
> PDepend-AST-Cache (`~/.pdepend`) kann veralten und liefert dann Ergebnisse
> für einen alten Dateistand. Vor verlässlichen Messungen `rm -rf ~/.pdepend`.
> Die CI ist von beidem nicht betroffen (frische Umgebung).

---

## `app/`: Baseline auf 0 ✅

**`phpstan-baseline.neon` und `phpmd.baseline.xml` sind leer.** PHPStan
(Level 9) und PHPMD laufen über `app/` und `legacy/` ohne
Baseline-Einträge.

- **PHPMD-Regel `StaticAccess`:** Laravel-Facades (`Auth`, `DB`, `Hash`, …)
  und `Carbon` sind in `phpmd.xml` als Ausnahmen eingetragen, weil sie
  bewusst statisch sind. Statische Zugriffe auf Eloquent-Modelle und
  Legacy-Libs sind weiter methodengenau unterdrückt.
- **Gemischte Werte** (`mixed` aus Legacy-Arrays) liest der Trait
  `App\Support\Concerns\ReadsMixedValues` (`asInt/asFloat/asString`).
- **Aufgeteilt:**
  - `PreferencesController` → `VacationModeService` (Urlaubsmodus) und
    `AccountUpdateService` (Name/Passwort/E-Mail). Die Sortier-Optionen und
    das Formular-Parsing liegen im `PreferencesService`.
  - `RegisterController` → `RegistrationService`.
  - `TechnologyInfoService` (Komplexität 131) → `JumpGateService`,
    `TechnologyTableService` (Level-Tabellen) und `TearDownService`. Die
    Ausgabe ist für alle Objekte auf Stufe 0 und 5, Sprungtor
    eingeschlossen, byte-identisch zum Stand davor geprüft.
  - `ResetController` arbeitet über eine Feld→Reset-Tabelle,
    `ResearchController::getActionLink()` hat 8 statt 10 Parameter, und
    `BuildingQueueService::add()` ist in Feldprüfung, Auftragsplanung und
    Bezahlung zerlegt.

**Dabei gefunden und gefixt:**

- 🔴→✅ **XSS-Fix umgehbar über die Einstellungen:** Der Namenswechsel in den
  Einstellungen prüfte nur die Länge. Die Namensregel aus `Changenick`
  (ASCII alphanumerisch, 4–20) galt dort nicht, so ließ sich ein Name mit
  Markup weiter setzen. Beide Seiten nutzen jetzt
  `PreferencesService::isValidNickname()`. Regressionstest.
- ✅ **Registrierung nach Fehler:** Scheiterte das Anlegen (z. B. beim
  Heimatplaneten), wurde zurückgerollt, aber mit dem schon erzeugten
  User-Objekt weitergemacht: Willkommensnachricht und -mail gingen an einen
  Spieler, den es nicht gab, und der Fehler wurde verschluckt. Jetzt kommt
  die Fehlermeldung, und der Fehler wird geloggt (`report()`). Tests für
  beide Fälle.
- ✅ **Anzeige `Changenick`:** Ein ungültiger Name stand in der
  Fehlermeldung doppelt escaped (`&lt;`), weil die Meldung ohnehin mit
  `{{ }}` ausgegeben wird.

---

## Prinzipien für jede Migration

1. **Ein Modul = ein PR**, Legacy-Datei am Ende löschen (kein Parallelbetrieb).
2. **Baseline schrumpfen**: pro portiertem Modul die zugehörigen PHPStan/PHPMD-Einträge
   entfernen — messbarer Fortschritt.
3. **Read-only vor Schreib-Logik** innerhalb jeder Welle.
4. Bei Spiellogik: **erst ein Test, der das Legacy-Verhalten festnagelt**, dann portieren.

---

## Begleitende Audit-Findings (unabhängig von der Welle einplanen)

- ✅ **Trader-Crash:** erledigt, `TraderLayerController` wurde in Welle 1 als
  toter Code entfernt.
- 🔴→✅ **Admin setzt Passwort, Spieler kann sich nicht einloggen** (gefixt):
  Die Benutzerbearbeitung im Admin hashte das neue Passwort selbst, danach
  hashte es der Passwort-Mutator des Modells ein zweites Mal. Jetzt hasht nur
  der Mutator. Test.
- ✅ **Mass-Assignment `authlevel`** (gehärtet): Der Rang ist nicht mehr
  `$fillable`; Registrierung, Installer, Bots und Admin setzen ihn explizit.
  Tests.
- ✅ **Admin-Schreibschleifen** (gefixt): Planeten-, Gebäude-, Schiffs-,
  Verteidigungs-, Forschungs- und Premium-Formular schrieben jedes Feld mit
  passendem Präfix als Spaltennamen. Ein unbekannter Name ließ die Abfrage
  scheitern, und `planet_user_id`, `research_user_id`,
  `research_current_research` oder `premium_user_id` ließen sich
  überschreiben. Jetzt gelten feste Allowlists (Planetenfelder als Liste,
  der Rest aus der `GameObjectRegistry`). Tests, Gegenprobe schlägt an.
- 🔴→✅ **Passwort-Reset ohne Token** (gefixt): „Passwort vergessen“ ersetzte
  das Passwort sofort durch ein zufälliges und schickte es im Klartext. Wer
  die E-Mail-Adresse eines Spielers kannte, konnte ihn so jederzeit
  aussperren; außerdem verriet die Antwort, ob es ein Konto zur Adresse gibt.
  Jetzt läuft es über Laravels Password-Broker: Die Mail enthält einen Link
  mit Einmal-Token (60 Minuten gültig, höchstens einer pro Minute), das
  Passwort ändert sich erst beim Setzen eines neuen, und die Antwort ist für
  jede Adresse gleich. Neue Seite `account/reset/{token}`. Tests.
- ✅ **Willkommensmail ohne Passwort** (gefixt): Sie schickte das gerade
  gewählte Passwort im Klartext zurück.
- ✅ **Öffentlicher `APP_KEY`** (gefixt): Der Installer erzeugte keinen
  eigenen Key, jede Installation aus dem Release-Paket behielt den Key aus
  `.env.example`. Der Installer erzeugt am Ende jetzt einen eigenen, falls
  noch der öffentliche oder keiner gesetzt ist (`.env.example` behält einen
  Key, sonst startet der Installer nicht). **Bestehende Installationen**
  mit dem öffentlichen Key sollten einmalig `php artisan key:generate`
  ausführen (meldet alle Spieler einmal ab).
- ✅ **Debug-Modus ab Werk** (gefixt): `.env.example`, aus der das
  Release-Paket installiert wird, hatte `APP_ENV=local` und `APP_DEBUG=true`.
  Fehlerseiten zeigten damit jedem Besucher Quelltext, SQL-Abfragen und
  Anfragedaten. Jetzt `APP_ENV=production` und `APP_DEBUG=false` (README
  erklärt, wie man es lokal einschaltet), und die Admin-Startseite warnt,
  solange der Debug-Modus an ist. **Bestehende Installationen** sollten in
  ihrer `.env` beides umstellen. Test.
- ✅ **Inline-`@phpstan-ignore`** entfernt: 15 Unterdrückungen außerhalb der
  Baseline, jetzt über `ReadsMixedValues` bzw. korrekte Typen gelöst.

- ✅ **Buddies: fremde Anfragen** (gefixt): `acceptRequest`/`removeRequest`
  luden die Anfrage per `bid` ohne Bezug zum Spieler und schickten die
  Benachrichtigung („angenommen“/„abgelehnt“/„gelöscht“) **vor** der
  Berechtigungsprüfung an den Absender. Mit beliebigen Ids ließen sich so
  Nachrichten über fremde Anfragen auslösen; eine unbekannte Id crashte
  (`false` an den Entity-Konstruktor → `TypeError`). `findBuddy()` sucht jetzt
  nur Anfragen des Spielers, annehmen darf nur der Empfänger. 3 DB-Tests.

- 🔴→✅ **Verlorener Spiel-Bootstrap** (gefixt): Mit der letzten
  promoteten Seite lief der Legacy-Bootstrap `Core\Common` (über
  `legacy/public/game.php`) für **keine** Spielseite mehr; `LegacyController`
  holte nur den Tick nach. Damit fehlten unbemerkt:
  - **Server geschlossen** (`game_enable`): Nicht-Admins konnten weiterspielen.
  - **Bann-Prüfung**: gebannte Spieler mit laufender Session spielten weiter
    (permanente Banns wären auch im alten Code durchgerutscht: `null > time()`).
  - **Spielersprache** (`Functions::setLanguage()`) und **Spiel-Zeitzone**
    (`date_time_zone`).
  - **Konstanten** aus `legacy/config/constants.php`: `LIB_PATH` (→ **jeder
    Kampf** crashte mit „Undefined constant“), `DPATH` (Galaxie-Aktionen,
    Feind-Links), `XGP_ROOT`/`IMG_PATH` (BBCode-Bilder, dazu ein Server-Pfad im
    `src`), `SYSTEM_ROOT` (Session-Redirects in `Users`).

  Jetzt läuft vor jeder Spielseite `App\Services\Game\GameBootstrap`
  (Sprache, Zeitzone, Tick, Server-/Bann-Prüfung; 8 Tests). `DPATH` steht in
  `config/legacy/constants.php`, die anderen Stellen brauchen ihre Konstante
  nicht mehr. `GlobalConstantsTest` prüft per Tokenizer, dass jede in `app/`
  und `legacy/` benutzte globale Konstante nach dem Boot definiert ist. Der
  tote Legacy-Pfad (`app/Http/game.php`, `legacy/public/game.php`,
  `Core\Common`, `legacy/config/constants.php`, `SecurePageLib`, die nach dem
  Request-Parsing nur noch Superglobals umschrieb) ist entfernt; unbekannte
  Seiten leiten wie bisher auf die Übersicht um.

- 🔴→✅ **Stored XSS in privaten Nachrichten und über Spielernamen**
  (gefixt): Betreff und Text privater Nachrichten (Chat, Allianz-Rundmail,
  Antwort auf Bewerbungen) werden mit `{!! !!}` ausgegeben, die Eingaben
  aber nur SQL-escaped gespeichert. Jeder Spieler konnte so HTML/JavaScript
  an jeden anderen schicken. Und `Changenick` prüfte nur die Länge, ein Name
  wie `<svg/onload=…>` lief überall, wo Namen roh ausgegeben werden. Jetzt
  werden diese Eingaben HTML-escaped, und Namen müssen wie bei der
  Registrierung ASCII-alphanumerisch sein. Das SQL-Escaping
  (`StringsHelper::escapeString`) ist entfernt: Die Werte werden gebunden
  gespeichert, das Escaping machte aus `it's` ein `it\'s` und aus
  Zeilenumbrüchen ein wörtliches `\n` (auch in Systemtexten wie den
  Expeditionsberichten und in Flotten-Shortcut-Namen). Bereits gespeicherte
  Nachrichten bleiben unverändert. Tests: Chat (DB), Namensregeln.
- 🔴→✅ **Sprungtor-Seite crashte ab Stufe 2** (gefixt): Die Wartezeit
  `3600 * (1 / Stufe)` ist ab Stufe 2 ein float, und `Functions::chronoApplet()`
  (natives `int`) warf dann während der Abklingzeit einen `TypeError`: Die
  Technologie-Detailseite des Sprungtors war nicht aufrufbar. Die Wartezeit
  ist jetzt ganzzahlig (`intdiv`). Regressionstest plus Test, dass ein Sprung
  während der Abklingzeit abgelehnt wird. Beim Typisieren liest
  `TechnologyInfoService` Preise, Kampfwerte, Rapid Fire und
  Produktionsformeln direkt aus der `GameObjectRegistry` statt aus
  untypisierten Legacy-Arrays. Die Ausgabe ist für alle Objekte auf Stufe 0
  und 5 byte-identisch geprüft.
- ✅ **Allianz-Ränge: Mitglieder rutschten beim Löschen eines Rangs um**
  (gefixt): Beim Löschen rücken die folgenden Ränge eine Id auf, die
  `ally_rank_id` der Mitglieder blieben aber stehen. Mitglieder der
  folgenden Ränge bekamen dadurch Name und Rechte des jeweils nächsten Rangs
  (Rechte-Eskalation möglich), Mitglieder des gelöschten Rangs den
  nachfolgenden. Und über `?d=0` bzw. `?d=1` ließen sich im Spiel Gründer-
  und Neuling-Rang löschen; die Oberfläche blendete den Link nur aus.
  `AllianceRankService::deleteRank()` (Spiel und Admin) macht die
  Mitglieder des gelöschten Rangs zu Neulingen, zieht die folgenden nach
  und speichert alles in einer Transaktion. `Ranks` lässt Rang 0 und 1 nicht
  mehr löschen. DB-Tests.
- ✅ **Intergalaktisches Forschungsnetzwerk** (entschieden): Mit dem Netzwerk
  zählen **alle** Labore des Spielers, so wie das Spiel es bisher schon
  rechnete. Die Abfrage behauptete per `ORDER BY … LIMIT Stufe+1` etwas
  anderes, `LIMIT` begrenzt aber keine Summe; außerdem lehnt MySQL im
  strikten Modus (`ONLY_FULL_GROUP_BY`, Laravels Standard) ein `ORDER BY` auf
  eine nicht aggregierte Spalte in so einer Abfrage ab, die Forschungsseite
  konnte ab Netzwerk-Stufe 1 also abbrechen. Jetzt eine einfache Summe. Tests.
- 🔴→✅ **XSS-Audit Spielereingaben** (gefixt). Geprüft wurden die von
  Spielern gesetzten Texte und ihr Weg in die rund 250 `{!! !!}`-Ausgaben:
  - **Allianztexte (BBCode):** `BBCodeLib` escaped den Text jetzt vor dem
    Rendern. Tag-Argumente werden nur gültig übernommen: Farben/Hintergrund
    `#?[a-z0-9]+`, Schrift `[a-z0-9 ,-]+`, Größe 1–2 Ziffern, Links nur
    http(s)/ftp, Bilder http(s) oder ein einfacher Pfad, E-Mail per
    `FILTER_VALIDATE_EMAIL`. Vorher ging `<script>` durch, und
    `[img]x" onerror="…[/img]`, `[color=…" onmouseover=…]` oder
    `[url=JavaScript:…]` brachen aus. 9 Payload-Tests, 8 davon schlugen gegen
    die alte Version an.
  - **Allianzname und -tag** wurden nur auf die Länge geprüft und an vielen
    Stellen roh ausgegeben (Galaxie, Statistik, Suche, Nachrichten). Jetzt
    sind `< > " ' & \` beim Gründen, Umbenennen und im Admin verboten.
  - **Allianzbild/-homepage:** `FILTER_VALIDATE_URL` akzeptiert
    `javascript:` und Anführungszeichen (`<img src="http://x/"onerror=…">`).
    Erlaubt sind nur noch http(s)-Adressen ohne Anführungszeichen und
    spitze Klammern, die Ausgabe wird zusätzlich escaped.
  - **Rangnamen:** Umbenennen entfernt Tags jetzt wie beim Anlegen.
  - **Bewerbungstext:** wird escaped mit Zeilenumbrüchen ausgegeben (vorher
    escaped *nach* `nl2br`, also mit sichtbarem `<br />`). Der Allianztag
    in der Warte-Meldung wird escaped.
  - **Flotten-Shortcuts:** Namen werden in der Zielauswahl escaped
    (Self-XSS).
  - Geprüft und in Ordnung: Planetennamen (Zeichenprüfung), Buddy-Texte
    (`strip_tags`), Notizen, Spielernamen (Regel siehe oben).

  **Altdaten:** `php artisan xgp:clean-player-texts` (erst mit `--dry-run`
  ansehen) bereinigt einmalig, was vor diesen Fixes gespeichert wurde und die
  heutigen Eingaberegeln nicht erfüllt: Spielernamen (nur ASCII-Buchstaben
  und -Ziffern, sonst `Player<Id>`), Allianzname und -tag (ohne
  `< > " ' & \`, sonst `Alliance<Id>`/`A<Id>`), Allianzbild und -homepage
  (nur http(s), sonst leer), Rangnamen (ohne Tags) und Planetennamen. Nicht
  angefasst werden Spielernachrichten: Sie mischen Spielertext mit gewolltem
  HTML und werden nach einer Woche ohnehin gelöscht. Allianztexte,
  Bewerbungstexte, Buddy-Anfragen und Flotten-Shortcuts werden bei der
  Ausgabe escaped (Buddy-Anfragen neu in diesem Schritt).
- **Spanische Expeditionsberichte:** 34 leere Texte übersetzt, darunter alle,
  die das Spiel auswählt (neue Schiffe 5, Ressourcen 3/4, Dunkle Materie 2–5,
  Verzögerung 2–5, schnellere Rückkehr 1/2, nichts gefunden 4–6, Flotte
  verloren). Spanische Spieler bekamen dort bisher leere Berichte.
- **Englisch:** `exp_nothing_8` aus dem vorhandenen spanischen Text übersetzt.
  `exp_lost_2` und `exp_primitive_enemy_7` entfernt (in keiner Sprache ein
  Text, nirgends benutzt).
- **Fehlende spanische Texte ergänzt:** `admin/global.gn_info_title` (auch
  englisch war leer), `game/planetlayer.rp_wrong_pass`,
  `install/install.admin_create_success/fail`.
- **Platzhalter angeglichen:** Die englischen Dunkle-Materie-Texte nutzen wie
  die spanischen Menge und Name (`%s %s`), `al_created` nennt auch den Tag.
- **`LanguageFilesTest`:** prüft für jede Sprachdatei gleiche Schlüssel,
  keine leeren Texte und gleiche Platzhalter in beiden Sprachen.
- **Deutsch als dritte Sprache:** `lang/de` mit allen 89 Sprachdateien (Spiel,
  Admin, Installer, E-Mails, Laravel-Validierung). Die Sprache erscheint
  automatisch im Installer und in den Servereinstellungen (die Liste kommt aus
  den Verzeichnissen unter `lang/`). Durchgehend wird geduzt, und die üblichen
  deutschen OGame-Begriffe werden verwendet (Kleiner Transporter,
  Trümmerfeld, Sensorphalanx, Verbandsangriff …).
  - **Tabelle `languages`:** `LanguagesTableSeeder` legt `de` an; für
    bestehende Installationen fügt die Migration
    `400/2026_10_16_000001_add_german_language.php` die Zeile hinzu, falls sie
    fehlt (`php artisan migrate --path=database/migrations/400`; nach einer
    Installation mit dem Installer vorher einmal
    `php artisan xgp:mark-installed-updates`, siehe Smoke-Test).
  - **Changelog im Spiel:** zeigt die Einträge der Serversprache und, wenn es
    in ihr keine gibt (Deutsch), die englischen. Vorher blieb die Seite dann
    leer.
  - **Serversprache `spanish`:** `OptionsTableSeeder` speicherte als Sprache
    `spanish`, ein Wert ohne Sprachverzeichnis. Das Spiel fiel deshalb auf
    Englisch zurück, die Changelog-Seite war leer und in den
    Servereinstellungen war keine Sprache ausgewählt. Neu installiert wird
    jetzt `en`, und `400/2026_10_16_000002_normalize_server_language.php`
    setzt einen ungültigen Wert auf `en`, so dass das Spiel genauso aussieht
    wie bisher.
  - **Englische Texte korrigiert:** `abandon_homeplanet_with_colonies`,
    `mg_type.2` und `al_error_founder` standen auf Spanisch, und `fl_fleet`
    lautete „Fllet“. Der GitHub-Link in `hm_old_version` (`https//`) ist
    repariert.
  - **Forschungsnetzwerk-Beschreibung (en/es):** Sie beschreibt jetzt die
    entschiedene Regel, dass alle Labore zusammengezählt werden; vorher war
    von „Stufe+1 Laboren“ die Rede.
  - **Spanischer Platzhalter:** In der Hilfe zu Rundschreiben stand
    `% player%`; ersetzt wird aber nur `%player%`.
  - **`LanguageFilesTest`:** vergleicht jetzt Spanisch und Deutsch mit
    Englisch. Zusätzlich zu sprintf- und `:name`-Platzhaltern erkennt er die
    eigenen Platzhalter des Spiels (`%1%`, `%player%`, `%t`, `%m`, `%n`). Er
    meldet außerdem Sprachdateien, die es auf Englisch nicht gibt. Neue Tests
    gibt es für den Changelog-Rückfall und die beiden Migrationen.

## Smoke-Test gegen eine echte Datenbank

Bis hierhin war alles nur mit Unit-Tests auf SQLite und statischer Analyse
geprüft. Ablauf des Tests:

1. MariaDB 10.11 aufgesetzt und den Installer im Browser (Playwright)
   durchlaufen.
2. Alle Spiel- und Admin-Seiten in Deutsch, Spanisch und Englisch
   aufgerufen und dabei auf HTTP-Fehler, JS-Fehler, Log-Einträge, rohe
   Sprachschlüssel und übrig gebliebene Platzhalter geprüft.
3. Durchgespielt: Registrierung, Bauen, Forschung, Werft, Transport,
   Spionage, Angriff, Kolonisierung, Expedition, Allianz (gründen, öffnen,
   bewerben, annehmen, Texte), Buddies, Nachrichten, Notizen, Einstellungen,
   Kampfbericht und das Speichern aller Admin-Formulare. Danach wurde der
   Datenbankstand verglichen.

Gefunden und behoben, jeweils mit Test:

- 🔴→✅ **MariaDB: Spiel komplett unbenutzbar.** Die Flottenabfrage der
  Missionsverarbeitung nutzte `GROUP BY` mit `f.*`. MariaDB lehnt das im
  strikten Modus ab (`ONLY_FULL_GROUP_BY`, Laravels Standard), weil es anders
  als MySQL keine funktionale Abhängigkeit erkennt. Jeder Seitenaufruf brach
  ab, keine Flotte kam an. Die Planetennamen kommen jetzt aus Unterabfragen.
  Die Allianz-Statistik gruppiert nach allen ihren Spalten.
- 🔴→✅ **Flotten doppelt verarbeitet** (auch auf MySQL). Das alte
  `GROUP BY` enthielt den Planetennamen. Lagen ein zerstörter Planet und
  eine neue Kolonie auf denselben Koordinaten, kam die Flotte zweimal in die
  Verarbeitung, ihre Mission lief also doppelt. Gegenprobe gegen den alten
  Code im Test.
- 🔴→✅ **Kein CSRF-Schutz im Spiel.** `VerifyCsrfToken` war in der
  `game`-Gruppe auskommentiert, und kein Spielformular schickte ein Token.
  Eine fremde Seite konnte einem eingeloggten Spieler jedes Formular
  unterschieben: Flotten, Nachrichten, Einstellungen, Kontolöschung,
  Allianz. Der Schutz ist an, alle 32 POST-Formulare, der
  Flotten-Rückruf und der Galaxie-AJAX-Aufruf senden das Token. Tests prüfen
  die Middleware-Gruppen und jedes POST-Formular.
- 🔴→✅ **Übersicht stürzte ab** (`$gameTitle` fehlte im Layout), die
  wichtigste Seite nach dem Login. Ein View-Composer liefert den Titel für
  alle Spielseiten.
- 🔴→✅ **Tests liefen gegen die Spieldatenbank.** Ist das Spiel
  installiert, überschreibt `config/xgp-db-config.php` die Testkonfiguration;
  Tests legten Zeilen an, änderten und löschten sie in der echten Datenbank.
  Die Tests sind jetzt fest auf SQLite im Speicher gestellt und schreiben
  nicht mehr ins Log des Spiels (sie füllten die Fehlerliste im Admin).
- ✅ **Admin und Installer ohne JavaScript.** jQuery, Bootstrap,
  DataTables, Chart.js und Font Awesome fehlten im Repository, weil die
  Regel `vendor` in `.gitignore` auch `public/assets/admin/vendor`
  ausschloss. Menüs, Tabellen und Dialoge funktionierten nicht. Ergänzt sind
  die Dateien aus SB Admin 2 4.0.7 (die Version des vorhandenen CSS/JS),
  nur die tatsächlich eingebundenen, mit Lizenz.
- ✅ **Sprache nur auf Spielseiten.** Startseite, Registrierung, Login,
  Passwort-Reset, deren Mails und der ganze Admin waren immer Englisch.
  Eine in der Session liegende Sprache (etwa vom Installer) schlug die
  Servereinstellung. Die Middleware `ServerLanguage` setzt die Serversprache
  für alles außer den Installer. Darum hießen auch neue Heimatplaneten
  „Homeworld“ und die Willkommensnachricht war englisch.
- ✅ **Registrierung brach mit Fehlerseite ab**, wenn die Willkommens-Mail
  nicht rausging; das Konto war da schon angelegt. Die Admin-Rundmail hörte
  bei der ersten fehlerhaften Adresse auf, der Passwort-Reset scheiterte
  genauso. Mail-Fehler werden jetzt geloggt, und der Vorgang läuft weiter.
- ✅ **Admin: Spieler speichern setzte den Allianzrang zurück.** Die
  Allianz kam als String aus dem Formular und war damit nie gleich der
  gespeicherten Id. Jedes Speichern galt als Beitritt: Rang „Neuling“ und
  neues Beitrittsdatum.
- ✅ **Allianztexte im falschen Feld.** Das Formular schickte wörtlich
  `{t}`, deshalb wurden interner Text und Bewerbungstext als externer Text
  gespeichert und überschrieben ihn.
- ✅ **Reste der alten Template-Syntax:** Die Verbandsangriff-Seite war
  komplett unübersetzt (`{fl_fleet_union}` usw., leere Listen, ohne
  Layout). Der Forschungs-Countdown leitete auf `cp={tech_home}` weiter, die
  Startseite lud `{img_path}…png`. Ein Test verbietet solche Platzhalter in
  Views.
- ✅ **Spionagebericht** zeigte für Schiffe, Verteidigung und Forschung den
  rohen Sprachschlüssel. Die Golden-Fixture hatte das mit aufgezeichnet und
  ist gezielt angepasst.
- ✅ **Kampfbericht:** „Attacker“, „Defender“ und „Repaired Defense“
  standen fest im Template, jetzt übersetzt; der englische Text ist
  unverändert.
- ✅ Kleinigkeiten:
  - Nachrichtenseiten (`HaltWithView`) wurden als Fehler geloggt.
  - Händler: Tauschpreis als `%s` und englische Rohstoffnamen.
  - Admin-Sperrseite: JS-Fehler ohne gesperrte Spieler.
  - Startseite: Aufruf nicht existierender Cookie-Funktionen.
  - Deutsch: Spalte „Ziel“ doppelt (jetzt „Ankunft“).
  - Englisch: „Fllet“.
- ✅ **Abhängigkeiten:** `composer audit` meldete sechs Lücken, darunter
  eine hohe in `league/commonmark`, außerdem in `laravel/framework`,
  `league/flysystem`, `symfony/http-foundation` und `symfony/yaml`. Die
  betroffenen Pakete sind innerhalb der bestehenden Versionsgrenzen
  aktualisiert (Laravel 12.64 → 12.70). Danach liefen alle Tests und der
  Crawl erneut grün.

Nachgezogen:

- 🔴→✅ **Alle Spielaktionen nur noch per POST.** Die Controller lasen
  Aktionen auch aus der URL, denn `input()`, `has()` und `integer()`
  enthalten in Laravel die Query. Ein einfacher Link, etwa im Allianztext,
  in einer Nachricht oder auf einer fremden Seite, konnte deshalb vieles
  auslösen: bauen, abbrechen, forschen, Verteidigung bauen, Offiziere
  anheuern, Mitglieder entfernen, Ränge ändern oder löschen,
  Allianz-Einstellungen ändern, die Allianz auflösen oder übergeben,
  Buddies annehmen oder löschen, Nachrichten löschen, Planeten umbenennen
  oder aufgeben.
  - Jeder schreibende Zweig reagiert jetzt nur auf POST.
  - Aktions-Links tragen `data-post`. `public/assets/js/post-links.js`
    schickt sie als POST mit dem Token aus dem `csrf-token`-Meta-Tag;
    vorhandene Rückfragen bleiben, für Links aus PHP gibt es
    `FormatService::postLink()`.
  - Dabei behoben: Die Allianz wurde per GET ohne Rückfrage aufgelöst. Die
    Übergabe konnte jedes Mitglied mit Admin-Recht an einen beliebigen
    Spieler vornehmen, jetzt nur noch der Gründer an ein Mitglied mit
    „Rechter Hand“. Rangänderungen trafen auch Spieler fremder Allianzen.
    Buddy-Anfragen gingen auch an sich selbst und an nicht existierende
    Spieler.
  - `ActionsNeedPostMysqlTest` schickt jede Aktion als GET und prüft, dass
    die Datenbank unverändert bleibt; beim alten Code ändern 15 der Fälle
    Daten. Dazu eine POST-Gegenprobe.
- ✅ **Tests gegen MySQL/MariaDB.** Spielseiten nutzen SQL, das SQLite nicht
  kennt (`UPDATE … INNER JOIN`, `DELETE` über mehrere Tabellen). Darum
  blieben die Seiten ungetestet, und der `GROUP BY`-Fehler fiel nicht auf.
  - `tests/MysqlTestCase` führt solche Tests gegen `MYSQL_TEST_DATABASE`
    aus (ohne die Variable werden sie übersprungen).
  - Das CI bekommt einen PHPUnit-Job mit MariaDB-Service.
  - Der Flottenabfrage-Test läuft auch auf MariaDB und reproduziert mit der
    alten Abfrage den `ONLY_FULL_GROUP_BY`-Fehler.
- ✅ **Händler „Rohstoffe tauschen“** umgesetzt (`MerchantService`), nach
  den vorhandenen Admin-Einstellungen:
  - Der Ruf kostet `merchant_price` Dunkle Materie. Der Händler kauft den
    gewählten Rohstoff zu den Basiskursen 3/2/1, mal einem pro Ruf
    gewürfelten Faktor zwischen Minimum und Maximum. Bei Metall und Faktor
    0,88 gibt es also 2 · 0,88 / 3 Kristall je Metall.
  - Das Angebot bleibt beim Spieler (`premium_merchant_offer`, neue Spalte),
    bis ein Tausch es verbraucht.
  - Beim Tausch werden der verkaufte Rohstoff und der Platz im Speicher für
    die erhaltenen Rohstoffe geprüft. Alles läuft in einer Transaktion mit
    Sperre.
  - Die Seite zeigt Kurs und freien Speicher und rechnet die Kosten live
    mit.
  - Tests für Kurse, Kosten, Ruf und Tausch einschließlich aller
    Fehlerfälle; im Browser auf MariaDB durchgespielt.
- 🔴→✅ **Update-Befehl scheiterte nach einer Neuinstallation.** Der
  Installer baut die Tabellen aus `migrations/install`, trug aber keine der
  Update-Migrationen aus `migrations/400` ein.
  `php artisan migrate --path=database/migrations/400` führte deshalb alle
  Updates seit v3 erneut aus und brach mit „table already exists“ ab.
  - Der Installer trägt sie jetzt ein (`xgp:mark-installed-updates --all`).
  - Bestehende Installationen führen **einmal**
    `php artisan xgp:mark-installed-updates` aus. Das trägt die älteren
    Updates ein. Die Updates ab `2026_10_16` prüfen selbst, was sie ändern,
    und laufen danach normal. Mit Test.

- ✅ **Phalanx-Scan nur per POST.** Ein Scan kostet 10.000 Deuterium und
  lief als GET; ein fremder Link konnte also Deuterium verbrauchen lassen.
  - Die Galaxieansicht öffnet das Popup jetzt per POST mit Token, ein
    bloßer Aufruf leitet zur Galaxie um.
  - Die Gebäudebeschreibung nannte 5k Deuterium, jetzt die tatsächlichen
    10.000.
  - Test mit Gegenprobe gegen den alten Code.
- ✅ **Expeditionsergebnis „Händler“** umgesetzt; es endete bisher wie
  „nichts gefunden“.
  - Wie im Original kommt der Händler kostenlos, mit zufälligem Rohstoff
    und Kurs. Hat der Spieler schon ein Angebot, behält es seinen Rohstoff
    und den besseren der beiden Kurse.
  - Tests für das Angebot, die Kursverbesserung und das Expeditionsergebnis.

- ✅ **Expeditionsergebnisse „Piraten“ und „Aliens“** umgesetzt; beide
  endeten bisher wie „nichts gefunden“ (`ExpeditionBattle`).
  - Der Gegner greift die Expeditionsflotte an. Er hat deren Schiffstypen,
    jeweils mit einem Anteil der Stückzahl (aufgerundet): Piraten 30/50/80 %,
    Aliens 40/60/90 %, je nach Größe (normal 89 %, groß 10 %, riesig 1 %).
  - Technik: Piraten liegen 3 Stufen unter dem Spieler, Aliens 3 darüber.
  - Kampf im leeren Raum, also ohne Trümmerfeld und ohne Beute.
    Überlebende fliegen heim, eine vernichtete Flotte ist weg, und die
    Verluste zählen in die Statistik.
  - Der Spieler bekommt den Expeditionstext und den Kampfbericht
    („Kampfbericht Piraten [1:1:16]“).
  - Tests für die Regeln sowie für Sieg und Totalverlust auf MariaDB, dazu
    im Browser durchgespielt.

- ✅ **Schrotthändler** umgesetzt (`ScrapMerchantService`, Seite
  `traderScrap`), nach dem Original (Gameforge-Tutorial „Premium
  features“, 2019); die Seite war ein Platzhalter, der Link im Händlermenü
  auskommentiert.
  - Er kauft Schiffe und Verteidigungsanlagen des Planeten für 35 % ihres
    Baupreises, in denselben Rohstoffen, aber nur so viel, wie in die
    Speicher passt. Raketen kauft er nicht.
  - Feilschen hebt den Anteil um 5 bis 14 Punkte, höchstens auf 75 %.
    Danach kommt „Das ist mein letztes Wort!“, sonst einer der elf
    vorhandenen Sprüche. Das erste Feilschen kostet 2.000 Dunkle Materie,
    jedes weitere 2.000 mehr, höchstens 10.000.
  - Anteil und Zahl der Feilsch-Runden bleiben beim Spieler
    (`premium_scrap_rate`, `premium_scrap_bargains`, neue Spalten) bis zum
    nächsten Verkauf.
  - Grundanteil, Höchstanteil und Preis des ersten Feilschens sind im Admin
    unter „Premium“ einstellbar (neue Optionen per Update-Migration).
  - Verkauf in einer Transaktion mit Sperre, Aktionen nur per POST.
  - Tests für Anteil, Feilschen, Preisstaffel, Speichergrenze, Verkauf und
    Fehlerfälle; im Browser auf MariaDB durchgespielt.
  - Auktionator und Import/Export kamen später mit den Gegenständen
    (siehe „Gegenstände“).

- 🔴→✅ **Expeditionsergebnis „Schiffe“** neu geregelt
  (`ExpeditionService::getFoundShips`).
  - Fehler 1: Mit Todesstern, Recycler oder Kolonieschiff in der Flotte
    brach die Mission mit „Undefined array key“ ab; in anderen Fällen gab
    es davon ein Gratis-Exemplar.
  - Fehler 2: Die Fundmenge war ein Anteil jeder Schiffsart mal Punkte je
    Schiff plus eins. Aus 100 Großen Transportern wurden so 701.
  - Neue Regel wie im Original, siehe „Abgleich mit dem Original“ unten.
  - Tests für die Regel und auf MariaDB für die ganze Mission, mit
    Gegenprobe gegen den alten Code.
- ✅ **Rohstofffund ohne große Funde** (gefixt): Statt der Fundgröße wurde
  der Rohstofftyp übergeben, also gab es immer nur den normalen Fund
  (10–50fach). Jetzt gelten die Gewichte aus den Einstellungen.
- ✅ **Expeditionstexte**: Benutzt werden jetzt alle 16 „nichts
  gefunden“- und alle 7 „Schiffe gefunden“-Texte (bisher 6 bzw. 5). Ohne
  passende Spielfunktion bleiben die Texte für Gegenstände
  (`exp_found_object_*`) und für unerforschte Sektoren
  (`exp_status_fresh_*`) ungenutzt.
- ✅ **`@todo`s**: keine mehr in `app/`. Ein toter Kommentarblock im Menü
  ist entfernt.
- ✅ **Abgleich mit dem Original.** Expeditionen folgen jetzt dem OGame-Wiki
  (owiki.de, „Expedition“), der Schrotthändler dem Gameforge-Tutorial
  „Premium features“ (2019).
  - **Expeditionspunkte**: Auch Recycler, Kolonieschiffe und Todessterne
    zählen (nur Solarsatelliten nicht). Obergrenze bei Platz 1 unter
    100.000 Punkten: 2.400 statt 2.500. Schiffsfunde haben eigene, halb so
    hohe Grenzen (1.250 bis 12.500).
  - **Rohstofffund**: Metall, Kristall und Deuterium im Verhältnis 3:2:1
    (bisher 68,5/24/7,5 %; die Update-Migration ändert nur unveränderte
    Gewichte). Faktoren gerade 10–50, 50–100, 100–200; mindestens 200
    Expeditionspunkte; die Flotte lädt nur ihre freie Ladekapazität
    abzüglich der Hälfte des Treibstoffs.
  - **Schiffsfund**: Strukturpunkte = Faktor (10–50, gerade 52–100, gerade
    102–200) × Expeditionspunkte, mindestens 10.000. Findbar sind die Typen
    bis eine Stufe über dem schwersten Schiff der Flotte (Spio, KT, LJ, GT,
    SJ, Kreuzer, SS, Bomber, Zerstörer, Reaper; einen Schlachtkreuzer gibt
    es nicht). Recycler, Kolonieschiffe und Todessterne finden allein
    nichts. Gezogen wird immer wieder ein Typ, jeder gleich
    wahrscheinlich, und eine Anzahl zwischen 1 und dem, was das Restbudget
    bezahlt; Schluss nach drei Zügen ohne Schiff.
  - **Verspätung**: + 2/3/5 × Haltezeit (bisher × Rückflugzeit).
  - **Verfrühung**: − Haltezeit / 3 × 2/3/5, Rückflug mindestens eine
    Sekunde (bisher halbe Rückflugzeit).
  - **Piraten/Aliens**: Anteil je Schiffstyp schwankt um ein Zehntel,
    Piraten runden ab, Aliens auf. Dazu kommen 5 LJ / 3 Kreuzer / 2 SS bei
    Piraten und 5 SJ / 3 SS (statt der fehlenden Schlachtkreuzer) / 2
    Zerstörer bei Aliens.
  - **Schwarzes Loch**: immer Totalverlust. Die Teilverluste von 1/34/67 %
    waren eine Zutat dieses Spiels.
  - **Unverändert, weil schon wie im Original**: Wahrscheinlichkeiten der
    Ergebnisse, Dunkle Materie (300–400 / 500–700 / ab 1.000), Größen
    89/10/1 %, Technik der Gegner ±3 Stufen, Kampf ohne Trümmerfeld,
    Händlerkurse und -preis.
  - Gegenstände kamen später dazu (siehe „Gegenstände“).
  - Tests mit festgelegten Zufallszahlen für alle Regeln, der Schiffsfund
    auf MariaDB; alle Ergebnisse im laufenden Spiel ausgelöst und geprüft.
- ✅ **Belastete Expeditionsgebiete** wie im Original
  (`ExpeditionAreaService`, neue Tabelle `expedition_areas`).
  - Jede Expedition erhöht die Belastung ihres Sonnensystems um 1, sie
    erholt sich um 10 pro Tag.
  - Bis 10 Expeditionen am Tag bleibt ein Gebiet frisch. Darüber endet eine
    Expedition öfter mit „nichts gefunden“: +2 % je Expedition über 10,
    höchstens 90 %. Jede Stunde Haltezeit über die erste ist ein weiterer
    Versuch (Chance hoch Stunden). Die Stufen und die Erholung stammen aus
    dem owiki, die Kurve nicht: Dafür nennt das Wiki keine Zahlen.
  - Mit einer Spionagesonde in der Flotte hängt am Bericht der
    Logbuch-Eintrag: frisch (bis 10), belebt (bis 25), voll (bis 50),
    überlaufen. Beim Flottenverlust fehlt er, wie im Original. Die zwei
    vorhandenen „frisch“-Texte werden jetzt genutzt, drei neue kamen dazu
    (de/en/es).
  - Tests für Belastung, Erholung, Chance und Logbuch, auf MariaDB für die
    ganze Mission; im laufenden Spiel geprüft.
- ✅ **Gegenstände (Items)** wie im Original ab Version 4.0 (owiki.de,
  „Items“), Teil 1: Katalog, Inventar, Shop und Wirkungen
  (`App\Services\Game\Items`, Seite `items` im Menü „Gegenstände“).
  - **Booster** Metall/Kristall/Deuterium: +10/20/30 % der Minenproduktion
    des Planeten für eine Woche (2.500/8.500/25.000 DM). Ein gleicher
    Booster verlängert, ein anderer Prozentsatz geht erst nach Ablauf. Die
    Rohstoffe bekommen den Booster genau für die Zeit, in der er wirkte; der
    Stundenwert zeigt ihn, solange er wirkt.
  - **Planetenfelder** +4/9/15 (75.000/150.000/300.000 DM) und
    **Mondfelder** +2/4/6 (30.000/50.000/100.000 DM), je einmal pro Planet
    oder Mond.
  - **Kraken / Detroid / Newtron** verkürzen den laufenden Gebäude-, Werft-
    bzw. Forschungsauftrag (und die folgenden) um 30 Min., 2 oder 6 Std.;
    nicht im Shop, wie im Original (Auktionator, Import/Export,
    Expeditionen).
  - Neue Tabellen `user_items`, `planet_boosters`, Spalte
    `planets.planet_item_fields`.
  - Tests für Katalog, Kauf, alle Wirkungen und Fehlerfälle; die
    Booster-Produktion auf MariaDB; im Browser durchgespielt.
- ✅ **Import/Export** (Teil 2 der Gegenstände, `ImportExportService`,
  Seite `traderImportExport`) wie im Original (owiki.de, „Import/Export“):
  - Jeden Tag ein Behälter mit unbekanntem Gegenstand (Booster, Kraken,
    Detroid, Newtron). Stufe und Preis sind am Tag für alle gleich: Bronze
    40.000–50.000, Silber 60.000–100.000, Gold 100.000–200.000
    Metalleinheiten, zahlbar in einem Rohstoff zu 1 : 1,5 : 3.
  - Ein Kauf am Tag; bis zu zweimal gegen einen anderen Behälter gleicher
    Stufe tauschen für 500 / 1.500 / 4.500 Dunkle Materie.
  - Wie oft welche Stufe kommt, nennt das Wiki nicht (nur: Gold seltener
    als Silber, Silber seltener als Bronze): hier 70 / 25 / 5 %.
  - Neue Tabelle `import_exports`. Tests; im Browser durchgespielt.
- ✅ **Auktionator** (Teil 3 der Gegenstände, `AuctionService`, Seite
  `traderAuctioneer`) wie im Original (owiki.de, „Auktionator“):
  - Von 6 bis 23 Uhr zu jeder vollen Stunde eine Auktion eines Gegenstands
    (alle außer Feldern), Ende nach 30–45 Minuten ±30 Sekunden; angezeigt
    wird die Restzeit in 5-Minuten-Schritten.
  - Gebote in Rohstoffen des Planeten, gezählt in Metalleinheiten
    (1 : 1,5 : 3), nie erstattet, erhöhbar; ein neues Gebot muss das
    Höchstgebot der anderen übertreffen.
  - Der Tick gibt dem Höchstbietenden nach dem Ende den Gegenstand und
    schickt ihm eine Nachricht.
  - Ehrenpunkte als Zahlungsmittel gibt es im Spiel nicht.
  - Neue Tabellen `auctions`, `auction_bids`. Tests; im Browser bis zum
    Gewinn durchgespielt.
- ✅ **Expeditionen finden Gegenstände** (Teil 4): neues Ergebnis „item“
  mit 0,5 %, genommen von „nichts gefunden“ (18,8 → 18,3 %), damit die
  Ergebnisse im Admin weiter 100 % ergeben; wo ein Admin „nichts“ geändert
  hat, startet das Gewicht bei 0. Ein normaler Fund bringt einen
  Bronze-, ein großer einen Silber-, ein sehr großer zwei Gold-Gegenstände
  (Texte `exp_found_object_1/2`). Häufigkeit und Stufen nennt das Wiki
  nicht, die Werte sind eine Annäherung. Tests; im Spiel geprüft.
- ℹ️ **Nicht übernommen** aus den Gegenständen des Originals: M.O.O.N.S.
  (ein gekaufter Mondschuss), Ehrenpunkte und Buddy-Punkte als
  Zahlungsmittel.
- 🔴→✅ **Admin-Reset der Bau- und Forschungsschleifen wirkungslos**
  (gefixt): Er leerte nur die Cache-Spalten der Planeten, die Aufträge in
  `building_queues`/`research_queues` liefen weiter und wurden fertig
  gebaut. Auch der Komplett-Reset ließ sie (und jetzt Gegenstände und
  Booster) stehen, weil dort die Fremdschlüssel abgeschaltet sind. Tests mit
  Gegenprobe; der Komplett-Reset hat jetzt einen MariaDB-Test.

Offen:

- (nichts aus dem Smoke-Test)
- ℹ️ Neue Allianzen sind standardmäßig geschlossen
  (`alliance_request_notallow = 0` heißt trotz des Namens „keine
  Bewerbungen“). So ist es durchgängig im Code, also eine
  Design-Entscheidung.

