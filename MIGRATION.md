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
| Tests | 2.001 PHPUnit-Tests (105 davon gegen MariaDB, ohne Testdatenbank übersprungen), dazu PHPStan, PHPMD (roh und Gate), php-cs-fixer und `composer audit` über das ganze Repo |
| Betrieb | mehrere Universen in getrennten Datenbanken mit Lobby und Monatsstart (`xgp:instance:*`); Dunkle Materie per Stripe oder WAX, Zahlungsarten je Universum |
| Sprachen | Englisch, Spanisch, Deutsch, Französisch, Portugiesisch (Brasilien), Niederländisch, Italienisch, Polnisch, Japanisch, Chinesisch (vereinfacht); Schlüssel und Platzhalter per Test gleich, gezählte Texte über `trans_choice` mit der Pluralregel jeder Sprache |
| Smoke-Test | Installer und die wichtigsten Spielabläufe gegen MariaDB im Browser durchgespielt (siehe unten); alle Spiel- und Admin-Seiten in allen zehn Sprachen gecrawlt, ohne echte Fehler (die drei erwarteten Meldungen stehen unter „Offen“ am Ende) |

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
- **Französisch als vierte Sprache:** `lang/fr` mit allen 92 Sprachdateien
  (Spiel, Admin, Installer, E-Mails, Laravel-Validierung). Wie Deutsch
  erscheint die Sprache automatisch im Installer und in den
  Servereinstellungen. Gesiezt wird wie im französischen OGame, mit dessen
  Begriffen (Petit transporteur, Champ de débris, Phalange de capteur,
  Antimatière, Attaque groupée …). Apostrophe sind typografisch (’), damit
  keine Escapes in den PHP-Strings nötig sind.
  - **Tabelle `languages`:** `LanguagesTableSeeder` legt `fr` an; für
    bestehende Installationen fügt
    `400/2026_10_16_000022_add_french_language.php` die Zeile hinzu, falls sie
    fehlt. Beim Zurückrollen bleibt sie, solange es Changelog-Einträge in ihr
    gibt.
  - **Changelog:** Ohne französische Einträge zeigt die Seite die englischen.
  - **Tests:** `LanguageFilesTest`, `AdminObjectNamesTest` und der Test der
    Expeditionsgebiete prüfen Französisch mit; ein Migrationstest prüft die
    neue Zeile. Alle Spiel- und Admin-Seiten mit Französisch als
    Serversprache gecrawlt, ohne neue Fehler.
- **Weitere Sprachen** (Reihenfolge: Portugiesisch (Brasilien),
  Niederländisch, Italienisch, Polnisch, Japanisch, Chinesisch
  (vereinfacht)): je Sprache alle 92 Dateien, ein Eintrag im
  `LanguagesTableSeeder`, eine Update-Migration für bestehende
  Installationen und die Aufnahme in `LanguageFilesTest`,
  `AdminObjectNamesTest`, den Test der Expeditionsgebiete und den
  Migrationstest (jetzt ein Data-Provider über alle nachgerüsteten
  Sprachen). Sprachordner mit Region heißen wie bei Laravel üblich
  (`pt_BR`, `zh_CN`); `lang_code` liefert das HTML-Attribut (`pt-BR`).
  Die Namen der Spielobjekte stehen je Sprache einmal in einer Namensliste,
  aus der die reinen Namensdateien erzeugt werden. Begriffe folgen dem
  jeweiligen OGame-Server.
  - ✅ **Portugiesisch (Brasilien)** (`pt_BR`, Migration `000023`): Anrede
    „você“, Begriffe des brasilianischen OGame (Cargueiro Pequeno, Campo de
    Destroços, Sensor Phalanx, Matéria Escura …). Mit `pt_BR` als
    Serversprache gecrawlt, ohne neue Fehler. Der Crawl meldete einmal
    `/admin/ajax/update/check` als abgebrochen: Die Seite fragt
    `updates.xgproyect.org` mit 5 Sekunden Timeout ab, und der Crawler
    verlässt sie vorher; das hängt nicht an der Sprache.
  - ✅ **Niederländisch** (`nl`, Migration `000024`): Anrede „je“,
    Begriffe des niederländischen OGame (Klein vrachtschip, Puinveld,
    Sensor Phalanx, Donkere Materie, Melkweg …); die V7-Schiffe heißen wie
    dort Crawler, Reaper und Pathfinder. Mit `nl` gecrawlt, ohne neue
    Fehler.
  - ✅ **Italienisch** (`it`, Migration `000025`): Anrede „tu“, Begriffe
    des italienischen OGame (Cargo leggero, Campo detriti, Falange di
    sensori, Materia Oscura, Morte Nera …). Wo das Spiel Pluralendungen
    anhängt (Galaxie: „membro“/„nave“), steht wie im Englischen „(i)“, weil
    Italienisch die Endung austauscht statt sie anzuhängen. Mit `it`
    gecrawlt, ohne neue Fehler.
  - ✅ **Polnisch** (`pl`, Migration `000026`): Anrede „ty“, Begriffe des
    polnischen OGame (Mały transporter, Pole zniszczeń, Falanga czujników,
    Ciemna Materia, Gwiazda Śmierci, Pełzacz/Rozpruwacz/Pionier …). Die
    Pluraltexte (`bn_players_banned`, `er_errors`, `er_occurrences`) nutzen
    feste Bereiche mit „Gracze: :count“, weil Polnisch nach 2–4 und ab 5
    verschiedene Formen braucht; wo die Galaxie Endungen anhängt, steht eine
    zählbare Form („szt.“, „członkowie: 5“). Mit `pl` gecrawlt, ohne neue
    Fehler.
  - ✅ **Japanisch** (`ja`, Migration `000027`): höfliche Form (です/ます),
    Begriffe des japanischen OGame (小型輸送機, デブリフィールド,
    センサーファランクス, ダークマター, デススター …). Wo englische Sätze aus
    Bausteinen zusammengesetzt werden (Flottenereignisse), sind die Bausteine
    so gewählt, dass die japanische Reihenfolge lesbar bleibt
    („あなたの艦隊 が移動中: A → B。任務: …“); die Platzhalter bleiben in der
    englischen Reihenfolge, damit der Test sie vergleichen kann. Mit `ja`
    gecrawlt, ohne neue Fehler.
  - ✅ **Chinesisch (vereinfacht)** (`zh_CN`, Migration `000028`,
    `lang_code` `zh-CN`): Anrede „你“, eingeführte Begriffe der chinesischen
    OGame-Gemeinschaft (小型运输舰, 废墟场, 传感器阵列, 暗物质, 死星 …).
    Satzbausteine und Platzhalter wie beim Japanischen. Mit `zh_CN`
    gecrawlt, ohne neue Fehler.
- ✅ **Willkommensnachricht ohne tote Links:** Die Nachricht an neue Spieler
  verlinkte `tutorial`, `irc`, `forum` und `support`, Seiten, die das Spiel
  nicht hat, und schickte Spieler in einen IRC-Kanal des alten Projekts.
  Jetzt steht dort der in den Servereinstellungen hinterlegte Forum-Link
  (`forum_url`), ohne Forum gar kein Link. Der Text ist in drei Schlüssel
  geteilt (`re_welcome_message_content`, `…_forum`, `…_closing`).
  Außerdem korrigiert:
  - Die Nachricht versprach „wird in 24 Stunden gelöscht“, die Bereinigung
    löscht Nachrichten aber nach einer Woche.
  - Zeilenumbrüche und `<br>` zusammen ergaben durch `nl2br` doppelte
    Leerzeilen.
  - Der Spielername wird escaped eingesetzt.
  - Das Spanische hatte einen eigenen, veralteten Text; es folgt jetzt den
    anderen Sprachen. Dabei standen in `es` wörtliche `\u00e1`-Escapes in
    acht Texten, die als Validierungsmeldung sichtbar wurden.

  Tests (mit und ohne Forum, Escaping); im Browser registriert und die
  Nachricht geprüft.
- ✅ **Pluralformen über `trans_choice`:** Das Spiel klebte Endungen an
  („member“ + „s“, „ship“ + „s“, „rocket(s)“, „application/s“). Das
  passt nur fürs Englische und Spanische. Alle gezählten Texte laufen jetzt
  über `trans_choice`, damit jede Sprache ihre Regel nutzt: eine Form im
  Japanischen und Chinesischen, drei im Polnischen (1 statek, 2 statki,
  5 statków), „0 und 1“ als Singular im Französischen:
  - Galaxie: Mitgliederzahl im Allianz-Tooltip (`gl_alliance_members`).
  - Flottenereignisse: anfliegende Schiffe (`ev_ships_count`).
  - Raketenangriff: abgefangene Raketen (`mis_some_destroyed`).
  - Allianz: offene Bewerbungen (`al_no_request_pending`, vorher `%n`) und
    der Link „neue Bewerbungen“ (`al_new_requests`).
  - Admin: erstellte Bots (`bo_created`); die polnischen Texte für Fehler,
    Vorkommen und gesperrte Spieler nutzen jetzt auch drei Formen.

  Die zwei Meldungen, die die Galaxie in JavaScript zusammensetzt („Sende
  3 Schiffe nach …“, „nur N Schiffe gesendet“), haben keinen Zugriff auf
  `trans_choice`; sie sind jetzt ohne Plural formuliert („Sende Schiffe (3)
  nach 1:1:9 …“) und kommen als ein Text mit `:count`/`:target`.
  Mitbehoben:
  - Die Galaxie fragte `gl_not_enought_storage` ab (Tippfehler im Schlüssel);
    Spieler sahen den Schlüssel statt der Meldung.
  - Die JavaScript-Meldungen der Galaxie kommen per `@json` statt `{{ }}`,
    sonst wurde ein Apostroph (`doesn't`) zu `&#039;`.
  - Unbenutzte Texte entfernt: das `timing`-Array (Jahr/Jahre … Sekunden)
    in `game/global.php` und `mg_result_count`.

  Der Sprachtest vergleicht benannte Platzhalter (`:count`) jetzt als Menge,
  weil jede Pluralform sie wiederholt; `sprintf`-Platzhalter zählt er weiter
  genau. Neuer `PluralTranslationsTest`: jede Sprache liefert für jede
  Zahl genau eine Form mit der Zahl darin, Polnisch die richtige der drei
  Formen, und jeder `trans_choice`-Schlüssel im Code ist abgedeckt. Im
  Browser auf Deutsch, Polnisch und Japanisch geprüft (Tooltip, Sende-
  Meldung), mit `pl` gecrawlt, ohne neue Fehler.
- ✅ **Update-Prüfung abgeschaltet:** Das Admin-Panel fragte
  `updates.xgproyect.org` (Server des Originalprojekts) nach neuen Versionen,
  einmal per JavaScript auf jeder Admin-Seite (Glocke oben rechts) und einmal
  serverseitig beim Laden der Startseite. Letzteres blockierte bis zu einer
  Sekunde, wenn der Server nicht antwortete. Neuer Schalter
  `XGP_UPDATE_CHECK` (`config('version.update_check')`, Standard aus): aus
  heißt keine Anfrage nach außen, keine Glocke, kein JavaScript-Aufruf; die
  Route bleibt bestehen und antwortet leer (verträgt `route:cache`). Mit
  `XGP_UPDATE_CHECK=true` verhält sich alles wie vorher. Tests (aus: nichts
  gesendet; an: neuere Version erkannt); im Browser geprüft, dass das
  Admin-Panel keine Update-Anfrage mehr stellt.
- ✅ **Bots spielen selbst:** Admin-Bots waren bisher leere Konten mit
  Startplanet, die nach einem Monat ohne Login von der Aufräumroutine
  gelöscht wurden. Jetzt:
  - Bots sind per Spalte markiert (`user_is_bot`, `user_bot_next_action`;
    Migration `000029`, setzt vorhandene `@bots.local`-Konten nach).
  - Der Spiel-Tick lässt höchstens einmal pro Minute bis zu 10 fällige Bots
    handeln; jeder Bot kommt alle 10–20 Minuten dran. Ein Zug macht, was ein
    Seitenaufruf des Spielers täte (Produktion, fertige Aufträge), und gibt
    dann Aufträge über dieselben Warteschlangen und Regeln wie Spieler
    (`BuildingQueueService`, `ResearchQueueService`, Werft-Warteschlange):
    keine Abkürzungen, alles bezahlt. Fehler eines Zuges werden geloggt und
    beenden nie den Seitenaufruf.
  - Strategie (`BotStrategy`): bei Energiemangel nur Solarkraftwerk, sonst
    Energiepuffer, volle Speicher, zurückliegende Anlagen (Roboterfabrik,
    Raumschiffwerft, Forschungslabor, Nanitenfabrik nach Metallminenstufe),
    Kristall zwei und Deuterium vier Stufen hinter Metall, dann Metall; davon
    das dringendste bezahlbare, sonst wird gespart. Fehlt ein Rohstoff, der
    nicht produziert wird, kommt zuerst dessen Mine; übersteigt der Preis
    den Speicher, zuerst der Speicher. Forschung nach einer Eröffnungsliste,
    danach immer die billigste nützliche Stufe. Ein Viertel der Rohstoffe
    über dem doppelten Preis des nächsten Gebäudes geht in die beste
    erlaubte Verteidigung.
  - Bots handeln nicht (Händler, Auktionen). Allianzen siehe „Bots
    verbünden sich“.
  - Die Aufräumroutine löscht keine Bots mehr; handelnde Bots sind online.
  - Admin → Bots: Schalter „Bots spielen selbst“ (`bots_play`, Standard an).

  Tests: Strategie (Unit) und ein Zug auf MariaDB (baut, forscht,
  verteidigt, bleibt im Plus; Schalter und Minutenabstand; Aufräumen). Mit
  einer Schnellvorlauf-Simulation abgestimmt (300 Züge à 30 Minuten: Metall
  13, Kristall 11, Deuterium 9, Energie ausgeglichen, Forschung laufend).
  Im Browser Schalter und echten Tick geprüft, gecrawlt ohne neue Fehler.
- ✅ **Bots fliegen:** Bots verschicken jetzt Flotten, nach denselben Regeln
  wie die Flottenseiten (Schiffe auf dem Planeten, freie Flotten- und
  Expeditionsslots, Laderaum, Treibstoff, Flugzeit mit denselben Formeln;
  immer volle Geschwindigkeit). Höchstens eine Flotte pro Planet und Zug:
  - Heimatplanet:
    - Kolonien gründen, so viele Astrophysik erlaubt, auf der nächsten
      freien Position (4–12, mittlere zuerst) im eigenen oder einem
      Nachbarsystem.
    - Überfälle nach Spionage: Ist die Sonde angekommen, bewertet der Bot
      das Ziel nach dem, was der Bericht zeigt (Rohstoffe, Schiffe,
      Verteidigung). Er greift nur an, wenn das Ziel unverteidigt ist oder
      seine Kriegsschiffe es dreifach übertreffen (grober Kampfwert aus
      Angriff, Schild und Hülle) und die halbe Beute mindestens 5.000
      ergibt. Er schickt genug Transporter dafür mit.
    - Spionage beim nächsten Nachbarn im Umkreis von 10 Systemen, dasselbe
      Ziel höchstens alle 6 Stunden.
    - Expeditionen mit der Hälfte der Kleinen Transporter und einer Sonde,
      eine Stunde Haltezeit.
  - Kolonien bringen ihre Rohstoffe über der Reserve für das nächste Gebäude
    mit eigenen Transportern zum Heimatplaneten.
  - Ziele: nur andere Bots, außer der Admin erlaubt Angriffe auf Spieler
    (neuer Schalter „Bots greifen auch Spieler an“, Option
    `bots_attack_players`, Standard aus, Migration `000031`). Admins, Spieler im Urlaub und
    Noob-Schutz werden wie bei Spielern geachtet.
  - Werft (`BotShipyard`): ein gewünschtes Kolonieschiff zuerst (dafür wird
    gespart), dann 3 Spionagesonden, Kleine Transporter und Leichte Jäger
    (je bis 2 × Metallminenstufe, höchstens 40/50), dann Verteidigung.
    Kolonien halten nur Transporter. Forschung um Verbrennungstriebwerk 3,
    Impulstriebwerk 3, Spionage 4 und Astrophysik 1/3 ergänzt.
  - Energie: Bei Mangel baut der Bot Solar- oder Fusionskraftwerk, je
    nachdem, welches die nächste Stufe billiger pro Energieeinheit liefert
    (vorher nur Solar; ab Stufe 20 warteten Bots tagelang darauf).
  - Überfallen (Kampfbericht als Verteidiger in den letzten 24 Stunden),
    steckt der Bot alles über der Gebäude-Reserve in Verteidigung statt
    eines Viertels.
  - Das Kolonieschiff wird aus dem ganzen Vorrat bezahlt; reicht er nicht,
    baut die Werft weiter (vorher sparte sie und baute nichts, solange die
    Reserve das Kolonieschiff unbezahlbar machte – in der Simulation hatten
    so die meisten Bots am Ende kein einziges Schiff).

  Ein Fehler in einem Bot-Zug wird wie bisher nur gemeldet; die Bot-Tests
  prüfen jetzt aber, dass nichts gemeldet wurde (so fiel ein fehlendes
  Tabellenpräfix in einer Sortierung auf, das jeden Überfall verhindert
  hätte). Neuer `OptionMigrationsTest`: jede Option aus dem Seeder kommt
  auch per Update-Migration in bestehende Installationen; sonst scheitert
  die Seite, die sie liest (so geschehen mit der Bots-Admin-Seite, als die
  Option nachträglich in die schon ausgeführte Migration `000029` geraten
  war). Tests: Werft und Kampfwert (Unit), Kolonisieren, Expedition,
  Spionage mit anschließendem Überfall, kein Überfall auf Verteidigte ohne
  Kriegsschiffe, Spieler nur mit Schalter, Transport von der Kolonie
  (MariaDB).
- ✅ **Bot-Simulator (`tools/simulate-bots.php`):** lässt N Bots auf einer
  eigenen MariaDB-Datenbank (Name muss auf `_sim`/`_sim2` enden, sie wird
  geleert) viele Spielstunden gegeneinander spielen, mit dem echten
  Spiel-Tick. Die Zeit läuft über eine Simulationsuhr: `time()`, `date()`
  und `strtotime()` werden in allen Namensräumen von `app/` und `database/`
  auf die Uhr umgelenkt, Carbon per `setTestNow()`; in der Datenbank wird
  nichts verschoben. Jede Ausnahme, jeder Log-Eintrag (auch `report()`) und
  tägliche Invarianten (keine negativen Rohstoffe oder Schiffe, Felder,
  hängende Flotten und Warteschlangen, gültige Werft-Warteschlange, keine
  sinkenden Stufen, jeder Bot handelt) landen in einem Bericht (Markdown und
  JSON). `--self-test` pflanzt bekannte Fehler und prüft, dass jeder
  gemeldet wird; `--profile` misst die Phasen des Ticks; mit `--seed`
  reproduzierbar. 10 Bots × 1440 h × 10-Minuten-Schritte dauern etwa 25
  Minuten. Beispiel:
  `php -d memory_limit=2G tools/simulate-bots.php --db=xgp_sim --bots=10 --hours=1440 --seed=42`

  Ergebnis 10 Bots × 1440 h (60 Spieltage), Seed 42, mit allen Bot-Fixes:
  **0 Fehler, 0 Invariantenverletzungen.** Durchschnitt 15.561 Punkte
  (Bots nur mit Gebäuden: 4.933). 29 Kolonien (alle Bots bis auf einen am
  Limit von Astrophysik 5), 1.740 Expeditionen mit allen Ergebnisarten
  (Rohstoffe, Schiffe, Dunkle Materie, Piraten, Aliens, Verzögerung,
  verfrühte Rückkehr, Gegenstand, Schwarzes Loch, Händler), 2.228
  Transporte, 770 Spionageflüge, 24 Überfälle, 128 Kampfberichte,
  Trümmerfelder auch im tiefen Raum. Laufzeit 39 Minuten.
- ✅ **Bots führen Krieg, Monde entstehen:** In 24 Überfällen der ersten
  Simulation entstand kein Mond – kein Fehler: die Bots überfielen nur
  unverteidigte Ziele, es gab keine Trümmer (Mondchance 1 % je 100.000
  Trümmer, höchstens 20 %). Damit echte Schlachten entstehen, wurde die
  Bot-Logik in mehreren Simulationsrunden nachgeschärft:
  - **Kampfsimulator:** Vor einem Angriff auf ein verteidigtes Ziel rechnet
    der Bot den Kampf mit der Kampf-Engine des Spiels (OPBE) durch, mit
    der ganzen Flotte (auch den Transportern), den Kampftechniken beider
    Seiten und Schnellfeuer (`BotBattleSimulator`, ändert nichts).
    Angegriffen wird, wenn er gewinnt und Beute plus Trümmer die Verluste
    decken; ohne Transporter auch nur des Kampfes wegen.
  - **Werft:** Recycler, Kreuzer, Schwere und Leichte Jäger; bis zu vier
    Aufträge verschiedener Einheiten pro Zug; Schiffe im Einsatz zählen
    mit (vorher bestellte die Werft jede Runde eine Sonde nach, weil die
    Sonden unterwegs waren, und baute nichts anderes). Liegen Flotten- und
    Verteidigungspunkte unter einem Drittel der Gebäude- und
    Forschungspunkte, bezahlt der ganze Vorrat die Werft, sonst ein
    Viertel über der doppelten Gebäude-Reserve. Verteidigung hat eine
    Obergrenze je Metallminenstufe (doppelt nach einem Überfall), damit
    nicht alles Metall in Raketenwerfer geht.
  - **Wirtschaft:** Bei mehr als dreimal so viel Metall wie Kristall auf
    Lager baut der Bot die Kristallmine statt der Metallmine (vorher fehlte
    allen das Kristall für Schiffe). Für ein gewünschtes Kolonieschiff wird
    gespart.
  - **Reichweite:** Spionage, Angriffe und Mondzerstörung bis 100 Systeme
    (die Bots lagen im Schnitt 50 Systeme auseinander), Kolonien und
    Trümmerfelder bis 10.
  - **Recycler** bergen Trümmerfelder in der Nähe; **Monde** werden vom
    Heimatplaneten mit genau dem beliefert, was der nächste Ausbau kostet,
    und bauen Mondbasis und Sensorphalanx; **Todessterne** fliegen gegen
    Monde von Zielen, wenn der Kampfsimulator den Sieg vorhersagt.
  - **Simulator:** `--grant=STUNDE:SCHIFF:ANZAHL[:BOTS]` gibt Bots Schiffe
    wie ein Admin (Todessterne sind bei Geschwindigkeit 1 in Wochen nicht
    erreichbar); `--moon-unit=N` hebt die Mondchance für Testläufe (die
    Engine-Konstante `MOON_UNIT_PROB` ist dafür vorbelegbar, im Spiel
    bleibt sie 100.000).

  Tests: Kampfsimulator (Unit), Werft (Kriegsflotte, Obergrenzen, mehrere
  Aufträge, Militär-Vorrang), Angriff auf ein verteidigtes Ziel mit
  Trümmern, Kampf ohne Transporter, Recycler, Mondbelieferung und
  Mondbasis, Todessterne gegen einen Mond, Schiffe im Einsatz (MariaDB).
  Probelauf 720 h: 0 Fehler, 445 Kämpfe, 392 Angriffsflotten, Recycler im
  Einsatz, der erste Mond in einem der Läufe.
- ✅ **Langzeitlauf mit allen Bot-Funktionen:** 10 Bots × 1440 h (60
  Spieltage), Seed 42, Todessterne für 3 Bots ab Tag 40
  (`--grant=960:214:3:3`), Mondchance ×100 (`--moon-unit=1000`), 84
  Minuten: **0 Fehler, 0 Invariantenverletzungen.**
  - Krieg: 1.215 Kampfberichte, 965 Angriffsflotten, 4 Verbandsangriffe
    (2 mit Verbündeten), 275 Kämpfe mit mehr als zwei Spielern, 545
    Halteflüge, 31 Raketenangriffe (1.776 Verteidigungsanlagen zerstört),
    317 Recyclerflüge.
  - Monde: 14 entstanden, 4 von Todessternen zerstört (9
    Zerstörungsflüge); Monde wurden beliefert und ausgebaut.
  - Wirtschaft und Handel: 23 Kolonien, 3.464 Expeditionen mit allen
    Ergebnisarten, 1.324 Transporte, 18 bezahlte Händleraufrufe und 26
    Tauschgeschäfte, 442 Auktionsgebote (307 gewonnen), 4 Import/Export-
    Container, 260 eingesetzte Gegenstände.
  - Allianzen: 3 (3, 3 und 2 Bots, alle Klasse Krieger).

  Im ersten Abschlusslauf kam kein Verbandsangriff zustande (jedes Ziel
  war von Einzelüberfällen „gesperrt“), das wurde behoben; der Simulator
  zählt seitdem auch zerstörte Monde (das Spiel löscht Berichte nach einer
  Woche).
- ✅ **Bots forschen Astrophysik, wenn die Kolonie-Plätze voll sind:** Im
  Langzeitlauf belegte jeder Bot alle Kolonie-Plätze, kam aber nur auf
  Astrophysik 4–5 (3–4 Planeten nach 60 Tagen): nach der Eröffnungsliste
  nahm er immer die billigste Forschung, und Astrophysik (Faktor 1,75 je
  Stufe) ist fast nie die billigste. Jetzt geht Astrophysik vor, sobald kein
  Kolonie-Platz frei ist (fliegende Kolonieschiffe zählen mit), solange sie
  höchstens das Vierfache der billigsten Stufe kostet. Tests: Strategie
  (Unit) und ein Bot-Zug auf MariaDB, der mit freien Plätzen die billigste
  Stufe und mit vollen Astrophysik erforscht (Mutationsprobe: ohne die
  Weitergabe der freien Plätze schlägt er fehl). Nebenbei: ein Bot-Test
  verglich eine Flugzeit mit einem zweiten `time()` und schlug sporadisch
  fehl, wenn dazwischen die Sekunde wechselte.
- ✅ **Kauf-Button für Dunkle Materie nur mit eigenem Shop:** Der Button
  „Dunkle Materie erhalten“ auf der Premium-Seite verlinkte standardmäßig
  auf `https://www.xgproyect.org/game.php?page=premium`, die Seite des
  Originalprojekts, auf der niemand etwas für diesen Server kaufen kann.
  Der Standard von `premium_url` ist jetzt leer, und ohne eingetragene URL
  zeigt die Seite den Button nicht (eigenes Teil-Template
  `premium/buy.blade.php`). Migration `000032` leert in bestehenden
  Installationen nur den alten Standard (http/https, mit und ohne `www`);
  eine selbst eingetragene Shop-URL bleibt. Test: `PremiumBuyLinkTest`.
- ✅ **Mehrere Universen in getrennten Datenbanken (Lobby):** Ein Server
  betreibt jetzt beliebig viele Universen, jedes in einer eigenen
  Datenbank mit eigenen Konten, Optionen, Sitzungen und eigenem Cache.
  - *Registry:* Tabelle `instances` in der Basisdatenbank (Verbindung
    `central`, Kopie der Spielverbindung; Migration unter
    `database/migrations/central`). Spalten: Slug, Name, Datenbank,
    Modus `pay2win`/`play2win`, Status `open`/`closed`, Startzeit. Ohne
    Einträge läuft der Server wie bisher als ein einziges Universum.
  - *Umschalten:* Die Middleware `SelectInstance` liest das Cookie
    `xgp_universe` (unverschlüsselt) und setzt vor dem Sitzungsstart
    Datenbank und Cache-Präfix auf das gewählte Universum
    (`InstanceContext::activate`, verwirft auch die request-weiten
    Dienste wie die Optionen). Ohne Wahl, bei unbekanntem oder beendetem
    Universum geht es zur Lobby. Ausgenommen sind `/universes` und
    `/stripe/*`.
  - *Lobby `/universes`:* Liste der Universen (neueste zuerst) mit Modus
    und Erklärung, Startdatum und Spielerzahl ohne Bots; beendete
    Universen werden als „Beendet“ gezeigt. Die Startseite zeigt den
    Namen des Universums und den Link „Universum wechseln“; Sprachdateien
    `home/lobby` in allen zehn Sprachen.
  - *Befehle:*
    - `xgp:instance:create {slug} --name --mode --database --admin-name
      --admin-email --admin-password --option=name=value`: legt die
      Datenbank an (Standard `xgp_uni_<slug>`). Danach laufen dieselben
      Schritte wie im Installer, ohne Löschen: Tabellen, Update-Migrationen
      als gelaufen markiert, Changelog, Sprachen, Optionen und
      Administrator auf 1:1:1. Gesetzt werden `game_name` und
      `premium_mode`; Sprache und Zeitzone kommen aus dem Basisspiel.
      Der Befehl verweigert eine Datenbank, die schon ein Spiel enthält
      oder einem anderen Universum gehört.
    - `xgp:instance:adopt` übernimmt das bisher installierte Spiel als
      Universum.
    - `xgp:instance:list` zeigt die Universen.
    - `xgp:instance:update --name --mode --status` ändert ein Universum.
      Der Modus wird auch als Option in die Datenbank des Universums
      geschrieben.
    - `xgp:instance:migrate {slug?}` führt die Update-Migrationen in einem
      oder allen Universen aus.
  - *Monatlicher Start:* `xgp:instance:monthly` legt das Universum des
    Monats an, gesteuert über `config/instances.php` bzw. die Variablen
    `XGP_MONTHLY_*`: Slug und Name mit `:year`, `:month` und `:number`,
    Modus, Optionen, Administrator und `keep_open` (schließt alle bis auf
    die neuesten N Universen). Der Befehl kann gefahrlos erneut laufen.
    Der Scheduler startet ihn am Ersten des Monats um 00:05, wenn
    `XGP_MONTHLY_ENABLED=true` gesetzt ist; dafür braucht der Server den
    Cron-Eintrag `* * * * * php artisan schedule:run`.
  - *Nebenbei:* Das Anlegen des Administrators ist aus dem Installer in
    `AdminAccount` gezogen; Installer und Befehle teilen es.
  - *Tests:* `InstancesMysqlTest` auf MariaDB:
    - Anlegen mit eigener Datenbank, Admin und Optionen.
    - Abweisen doppelter Slugs, fremder Datenbanken, ungültiger Slugs
      und von Datenbanken, die schon ein Spiel enthalten.
    - Moduswechsel in Registry und Universum.
    - Lobby, Cookie und Umschalten der Datenbank je Request.
    - Unbekannte und beendete Universen.
    - Übernehmen des bestehenden Spiels.
    - Monatsstart einschließlich Wiederholung und Schließen.
  - *Live geprüft:* Lobby und Wechsel zwischen zwei Universen mit
    getrennten Admins. Beide wurden komplett gecrawlt (alle Spiel- und
    Admin-Seiten) und zeigen dieselben drei bekannten Treffer wie im
    Einzelbetrieb.
- ✅ **Bots handeln:** Bots nutzen jetzt den Händler, den Auktionator und
  Import/Export, einmal pro Zug nach den Planeten und mit dem Vorrat des
  Heimatplaneten, über dieselben Service-Methoden wie die Händlerseiten
  (`MerchantService::call/exchange`, `AuctionService::current/bid`,
  `ImportExportService::buy`, `ItemService::use`). Neue Klassen
  `BotTrader`, `BotBidder` und `BotTradeRules` (reine Rechenregeln), in
  `BotPlayerService::play()` nur ein Aufruf. Bedarf ist, was das nächste
  Gebäude und die nächste Forschung kosten (soweit sie nicht schon laufen);
  Überschuss ist der Vorrat darüber, Fehlbetrag der Bedarf darüber hinaus.
  Rohstoffe werden in Metalleinheiten verglichen (1 : 1,5 : 3).
  - Händler: nur wenn die eigenen Minen für einen Fehlbetrag länger als
    6 Stunden bräuchten. Ein Angebot, das der Bot schon hat (von einer
    Expedition), nutzt er, wenn der Händler einen Rohstoff kauft, von dem er
    übrig hat. Sonst ruft er ihn für Dunkle Materie zum Kauf seines größten
    Überschusses, wenn die Hälfte davon den ganzen Fehlbetrag auch zum
    schlechtesten Kurs (`merchant_base_min_exchange_rate`, neu
    `MerchantService::lowestFactor()`) bezahlt, und höchstens alle
    6 Stunden (`RateLimiter`). Er kauft bis zum Doppelten des Fehlbetrags,
    für höchstens die Hälfte des Überschusses und nur, was ins Lager passt.
  - Auktionator: mit großem Überschuss (Wert einer Tagesproduktion des
    Planeten, mindestens 50.000) bietet der Bot im Rohstoff seines größten
    Überschusses: zuerst ein Viertel seines Limits, überboten 10 % (mindestens
    1.000) über dem Höchstgebot, bis zum Limit. Limit: ein Viertel des
    Überschusses (bisherige Gebote eingerechnet), nie mehr, als der
    Gegenstand ihm wert ist – ein Booster die Mehrproduktion seiner Woche,
    Kraken/Detroid/Newtron die Produktion der gesparten Zeit.
  - Import/Export: mit großem Überschuss kauft er den Container des Tages,
    wenn ein unbekannter Gegenstand dieser Stufe im Schnitt den Preis wert
    ist und dieser im Limit liegt. Tauschen nie (gleiche Stufe, gleicher
    Erwartungswert, kostet Dunkle Materie).
  - Gegenstände (gewonnen, gekauft, von Expeditionen) setzt er auf dem
    Heimatplaneten ein; was gerade nicht wirkt (nichts im Bau, anderer
    Booster aktiv), wartet. Feld-Gegenstände und M.O.O.N.S. bleiben liegen.
  - Schrotthändler: bewusst nicht – er zahlt nur einen Teil des Preises,
    und Bots bauen Schiffe und Verteidigung für einen Zweck.

  Admin-Erklärung `bo_play_explanation` in allen 10 Sprachen ergänzt.
  Der Simulator zählt die Händleraktionen aus den Schreibzugriffen (der
  Händler führt kein Protokoll) und zeigt sie unter „Trading“. Tests:
  Regeln (Unit), auf MariaDB Händlerruf mit Tausch und Sperre für
  6 Stunden, Expeditionsangebot ohne Dunkle Materie, kein Handel ohne
  Überschuss, Bieten und Überbieten zweier Bots, Aufgeben ohne Überschuss,
  Einsatz des gewonnenen Boosters, Container einmal am Tag, Einsatz eines
  Gegenstands im Bot-Zug. Simulation 10 Bots × 720 h, Seed 42: **0 Fehler,
  0 Invariantenverletzungen**; 24 Auktionen, 23 Gebote und 2 Erhöhungen
  (205.184 Metalleinheiten), 21 von Bots gewonnen, 16 Gegenstände
  eingesetzt (8 Booster), 1 Händlertausch mit einem Expeditionsangebot.
  Kein bezahlter Händlerruf und kein Container: In 30 Tagen hat kein Bot
  3.500 Dunkle Materie (höchstens 1.801), und der Durchschnittswert eines
  Containers liegt bei dieser Produktion noch unter seinem Preis.
- ✅ **Bots verbünden sich:** Bots nutzen jetzt Allianzen, Halten,
  Verbandsangriff und Interplanetarraketen, mit denselben Regeln wie
  Spieler. Neue Klassen `BotAlliance`, `BotCoordinator`, `BotMissiles`;
  `BotPlayerService` ruft sie auf (Allianz einmal pro Zug, eine zusätzliche
  Flotte pro Zug vom Heimatplaneten nach `BotFleetPlanner`).
  - Allianzen (`BotAlliance`): Ein Bot ohne Allianz bewirbt sich bei der
    nächsten Bot-Allianz mit Platz (ein Mitglied in derselben Galaxie
    höchstens 50 Systeme entfernt, höchstens 3 Mitglieder samt
    Bewerbungen); gibt es keine, aber einen allianzlosen Bot in der Nähe,
    gründet er eine (Tag/Name nach seinem Heimatsystem, z. B. `B1-76`,
    Bewerbungen offen). Der Gründer nimmt Bots auf und lehnt Spieler ab,
    Bots treten keiner Allianz mit Spielern bei: Bots und Spieler teilen nie
    eine Allianz. Ist die erste Klassenwahl frei (nach 14 Tagen), wählt der
    Gründer „Krieger“. Eine Bewerbung bei einer verschwundenen, geschlossenen
    oder vollen Allianz zieht der Bot zurück.
  - Gründen, Bewerben, Zurückziehen und Beantworten stecken jetzt in
    `AllianceService`, den die Allianzseite und die Bots nutzen; die Seite
    verhält sich wie vorher (Prüfungen und Nachrichten bleiben dort).
  - Halten (Mission 5): Fliegt eine feindliche Angriffs- oder
    Verbandsangriffsflotte auf den Planeten eines Verbündeten, schickt ein
    Bot seine Kriegsschiffe dorthin, wenn sie vorher ankommen, und hält bis
    zwei Stunden nach dem Angriff (höchstens 32 Stunden); sie kämpfen als
    Verteidiger mit. Wurde ein Verbündeter in den letzten 24 Stunden
    angegriffen, hält die Hälfte der Kriegsschiffe 8 Stunden an seinem
    Heimatplaneten. `BotFleetService` erlaubt Halten wie die Flottenseite nur
    bei Allianzmitgliedern und Buddys, 0–32 Stunden, mit Noob-Schutz.
  - Verbandsangriff (Missionen 1 und 2): Ein Ziel in höchstens 100 Systemen,
    das der Bot oder ein Verbündeter in den letzten 3 Stunden ausspioniert
    hat und verteidigt ist, greift er mit den Verbündeten in der Nähe an,
    wenn er laut Kampfsimulator (`BotBattleSimulator`, die Kampf-Engine des
    Spiels) allein verliert, mit den Kriegsschiffen der Helfer aber gewinnt,
    und die halbe Beute mindestens 5.000 ergibt (beim Zusammenführen von
    einem groben Kampfwert auf den Simulator umgestellt, den auch der
    Einzelüberfall nutzt; sonst griff ein Bot allein an, was der
    Verbandsangriff für zu stark hielt):
    Er fliegt mit Kriegsschiffen und Transportern für die Beute los, eröffnet
    die Gruppe wie die Verbandsseite (`acs`, `acs_members`) und lädt die
    Helfer ein; die treten mit Mission 2 bei, wenn sie die Gruppe um
    höchstens 30 % ihrer Restflugzeit aufhalten. `send()` hat dafür einen
    optionalen Gruppenparameter und gleicht die Ankunftszeiten wie
    `Fleet4Controller` an (spätere Flotte verschiebt die Gruppe). Eine
    Allianz fliegt nur einen Verbandsangriff zur Zeit, ein in den letzten 24
    Stunden angegriffenes Ziel bleibt verschont, Verbündete nie (auch
    `BotFleetPlanner` spioniert und überfällt keine Verbündeten mehr).
    Die Kriegsschiffe dafür baut die Werft aller Bots (siehe „Bots führen
    Krieg“); ein eigener Kriegsschiff-Auftrag für Allianz-Bots, den es beim
    getrennten Entwickeln gab, entfiel beim Zusammenführen.
  - Raketen (Mission 10): Liegt ein Ziel in Raketenreichweite, spart der
    Heimatplanet ab Metallmine 18 und Impulstriebwerk 1 für ein Raketensilo
    bis Stufe 4 (Gebäude, Forschung und Werft des Heimatplaneten warten so
    lange; zwischen den Minen kam es nie zustande, in der Simulation blieb
    es sonst bei Stufe 2–3). Dann hält er bis zu 8 Interplanetarraketen
    (Siloplatz, je Auftrag aus einem Fünftel des Vorrats, erst wenn eine
    Spionagesonde da ist)
    und schießt sie wie die Galaxieseite (Silo 4, Impulstriebwerk,
    Reichweite, gleiche Galaxie, Noob-Schutz, Urlaub) auf die Verteidigung
    eines ausspionierten Ziels: so viele, wie nötig sind, samt denen, die
    Abfangraketen abfangen, und nur, wenn sie mindestens ihren eigenen Preis
    an Verteidigung zerstören. Der Start steckt jetzt in `MissileService`,
    den auch die Galaxieseite nutzt.
  - Aufklärung: Allianz-Bots mit Kriegsschiffen und Bots mit Raketen
    spionieren Ziele bis 100 Systeme, die kein Mitglied in den letzten 6
    Stunden ausspioniert hat.

  Tests (MariaDB): Nachbarn gründen und füllen eine Allianz, Spieler werden
  abgelehnt, Bots treten keiner Spielerallianz bei, Kriegerklasse; ein Bot
  hält bei einem bedrohten Verbündeten und kämpft im Kampfbericht mit; zwei
  Verbündete fliegen einen synchronisierten Verbandsangriff, der als ein
  Kampf mit beiden Flotten aufgelöst wird; kein Verbandsangriff auf
  Verbündete oder Spieler; Raketen zerstören Verteidigung (eine Abfangrakete
  eingerechnet), billige Verteidigung, Ziele außer Reichweite und
  Verbündete bleiben verschont; Silo wird nur mit Ziel in Reichweite
  angespart und bekommt erst mit Sonde Raketen; Allianz-Bots bauen
  Kriegsschiffe; `AllianceService`; die zwei Fleet4-Prüfungen. Unit:
  Siloschritt (samt Speicher), Raketenbestellung. Simulator: neuer Abschnitt „Cooperation“
  (Allianzen, Verbandsgruppen, Kämpfe mit mehr als zwei Spielern,
  Raketeneinschläge und zerstörte Verteidigung).

  Ergebnis 10 Bots × 1440 h, Seed 42 (`--db=xgp_b_sim`, 55 Minuten):
  **0 Fehler, 0 Invariantenverletzungen.** Drei Allianzen (3, 3 und 2
  Bots, alle Krieger; zwei Bots ohne Nachbarn blieben allein), 98
  Verbandsangriffe (60 mit beigetretenen Verbündeten, 73 ACS-Flotten), 218
  Halteflüge, 77 Kämpfe mit mehr als zwei Spielern (Verband oder haltende
  Verteidiger), 17 Raketeneinschläge mit 482 zerstörten Verteidigungsanlagen
  (der einzige Bot mit einem Fremden in Reichweite), 213 Kampfberichte.
  Der Krieg kostet: Durchschnitt 12.640 Punkte statt 15.561, 410 statt
  1.740 Expeditionen (Kriegsschiffe und Raketen gehen den Transportern der
  Werft vor). Abgestimmt in acht Läufen: Allianzgröße 4 → 3 (sonst lagen
  keine Fremden in Raketenreichweite), Reichweite 60 → 100 Systeme (sonst
  fanden sich keine Helfer), eigene Kriegsschiffe für Allianz-Bots (die
  Werft baute fast nur Transporter, Verbandsangriffe hatten nichts zu
  fliegen), ein Verbandsangriff pro Allianz zur Zeit und 24 Stunden Ruhe
  je Ziel (vorher 333 in 60 Tagen), Silo nur mit Ziel in Reichweite und mit
  Sparen (vorher blieb es bei Stufe 2–3, ohne Ziel kostete das Sparen alle
  Bots ein Fünftel ihrer Punkte), Raketen aus einem Fünftel des Vorrats
  (das freie Budget reichte nie für Kristall).

  Nebenbei behobene Fehler (je mit Regressionstest):
  - Allianz-Bewerbungen: Annehmen/Ablehnen nahm jede Benutzer-ID; ein
    Allianz-Admin konnte so jeden Spieler in seine Allianz holen
    (`ally_id` gesetzt) oder per „Ablehnen“ aus dessen Allianz werfen
    (`ally_id = 0`). Jetzt wird nur eine Bewerbung an diese Allianz
    beantwortet (`AllianceService::answer`).
  - `Fleet4Controller::isStayAllowed`: Zwei Spieler ohne Allianz galten als
    verbündet (0 = 0), Halten bei jedem Fremden ließ sich per Formular
    erzwingen (Schritt 3 bot es nicht an).
  - `Fleet4Controller::validateAcs`: Die Gruppe kam aus dem Formular; jeder
    konnte einem Verband beitreten, dessen Nummer und Ziel er kannte, ohne
    Einladung. Jetzt nur als Mitglied in `acs_members`.

  Außerdem schrieb die Galaxieseite das Raketenziel „all“ (nur per
  Formular-Manipulation möglich) als Text in die Ganzzahlspalte
  `fleet_target_obj`, im Strict-Modus ein Fehler; `MissileService` bekommt
  jetzt 0 (= alle Verteidigung der Reihe nach).
- ✅ **Einstellungen einmal pro Anfrage laden:** `SettingsService` war nicht
  als geteilte Instanz gebunden; jedes `app(SettingsService::class)` lud die
  ganze Tabelle `options` neu – im Tick allein etwa 15-mal, auf einer
  Spielseite 24- bis 54-mal (Galaxie). Jetzt ist er `scoped` gebunden
  (einmal pro Anfrage bzw. Job, auch unter Octane und Queue-Workern), und
  `write()` aktualisiert die geladenen Werte mit, damit ein geschriebener
  Wert sofort gelesen wird. Gemessen über das MariaDB-Abfrageprotokoll:
  2–4 Optionsabfragen pro Seite statt 24–54; der Simulator läuft 10 %
  schneller bei identischem Spielverlauf. Alle Schreibzugriffe im Code laufen
  über `write()`; zwei Tests, die Optionen mitten im Test direkt in die
  Tabelle schrieben, tun das jetzt auch. Neuer `SettingsServiceTest`.
- ✅ **Keine externen Inhalte mehr:** Spiel, Admin-Panel, Installer und
  E-Mails luden Inhalte von fremden Servern, und jeder Aufruf verriet diesen
  Servern, wer wo spielt:
  - das XG-Proyect-Logo von `xgproyect.org` (weiß: Admin-Seitenleiste,
    Admin-Login, Installer, Standard-Serverlogo der Startseite; schwarz:
    Willkommens-, Passwort- und Ankündigungs-Mail),
  - die Schrift Nunito von Google Fonts (Admin-Panel, Admin-Login,
    Installer),
  - Laravels Standardseite `welcome.blade.php` (Schrift von
    `fonts.bunny.net`); sie wurde nirgends benutzt und ist gelöscht,
  - Laravels Mail-Kopf hätte bei `APP_NAME=Laravel` das Laravel-Logo von
    `laravel.com` geladen; der Kopf liegt jetzt unter
    `resources/views/vendor/mail/html/header.blade.php` ohne diesen Zweig.

  Jetzt lokal unter `public/`: die Original-Logos (von `xgproyect.org`
  heruntergeladen, unverändert) als `assets/images/xgp-logo-white.png` und
  `xgp-logo-black.png`, Nunito als `assets/admin/vendor/nunito/` (die
  woff2-Dateien, die Google Fonts ausliefert, v32, variable Schrift 200–900,
  normal und kursiv, je Latin, Latin-Ext, Kyrillisch, Kyrillisch-Ext,
  Vietnamesisch; eigenes `css/nunito.css` mit denselben `unicode-range`s).
  Nunito steht unter der SIL Open Font License 1.1, die Lizenz liegt als
  `OFL.txt` daneben; die Logos gehören zum Projekt selbst (GPL). Eingebunden
  per `asset()`, das in E-Mails absolute URLs liefert (`APP_URL`).
  Serverlogo: Standard ist jetzt leer, leer heißt mitgeliefertes Logo
  (`WelcomeController`); Migration `000030` leert den alten Standardwert,
  ein vom Admin gesetztes Logo bleibt. Der Hilfetext unter Admin → Server
  sagt das (de/en). Einziger eingebauter Abruf nach außen bleibt die
  Update-Prüfung (siehe oben, Standard aus); Links, die man anklickt (z. B.
  „Powered by XG Proyect“), bleiben.

  Tests: `NoExternalResourcesTest` durchsucht alle Views nach `<link href>`,
  `src`/`srcset`/`poster`/`background`, `url(` und `@import` mit
  `http(s)://` oder `//` (normale `<a href>` erlaubt), alle CSS-Dateien in
  `public/` nach `url(` und `@import`, und prüft, dass jedes `asset(...)`
  einer View als Datei existiert. `WelcomeLogoTest`: leeres Logo zeigt das
  mitgelieferte, ein eigenes bleibt, die Migration ersetzt nur den alten
  Standard. Im Browser (Playwright, alle Anfragen mitgeschnitten):
  Admin-Login, Admin-Startseite und alle 26 Seiten der Seitenleiste,
  Installer (Start, Voraussetzungen, Datenbank), Startseite (mit altem
  Logo-Wert vor Migration `000030` eine Anfrage an `xgproyect.org`, danach
  keine), Spiel (Übersicht, Forschung, Werft, Rangliste, Imperium) und die
  gerenderte Willkommens-Mail: keine Anfrage verlässt `127.0.0.1`, Logo und
  Schrift laden.
- ✅ **Dunkle Materie kaufen (Stripe, nur Pay2Win):** Spieler eines
  Pay2Win-Universums (Option `premium_mode`) kaufen Dunkle Materie über
  Stripe Checkout; gutgeschrieben wird automatisch per Webhook.
  - Regel für den Button „Dunkle Materie erhalten“ (`DarkMatterShop`):
    Pay2Win **und** beide Stripe-Schlüssel gesetzt → Button zum eigenen
    Shop (`game.php?page=darkmatter`). Pay2Win ohne Stripe → wie bisher
    `premium_url`, falls eingetragen. Play2Win → kein Shop, kein Button
    (ohne Ausnahme, seit es die Zahlungsarten je Universum gibt). Ist der Shop zu, antworten
    Shop-Seite und Kauf-POST mit einer Meldung und zurück zur Premium-Seite.
  - Pakete in `config/premium.php` (Standard: 10.000 DM 1,99 €, 50.000
    7,99 €, 120.000 16,99 €, 300.000 34,99 €; Preise in Cent, Währung
    `STRIPE_CURRENCY`, Standard EUR). Der Kauf nimmt vom Spieler nur die
    Paket-ID; Menge, Preis und Währung kommen aus der Konfiguration.
  - Tabelle `dark_matter_orders` in der Datenbank des Universums
    (Migration `000034` in `install` und `400`): Spieler, Paket, Menge,
    Betrag (Cent), Währung, Provider, Checkout-Session (eindeutig),
    Payment-Intent, Status `pending`/`paid`/`expired`/`failed`/`refunded`,
    Zeiten als Unix-Sekunden. Modell `DarkMatterOrder`.
  - Kauf (POST mit CSRF): offene Bestellung, dann Checkout-Session über
    Stripes REST-API mit Laravels HTTP-Client (keine neue Abhängigkeit;
    `mode=payment`, `price_data`, Metadaten Bestellung/Spieler/Universum
    auch am Payment-Intent, `success_url`/`cancel_url` auf die
    Bestellseite des aktuellen Hosts, Ablauf nach 1 h, Idempotency-Key)
    und Weiterleitung zu Stripe. Ist Stripe nicht erreichbar oder lehnt ab,
    wird die Bestellung `failed` und der Spieler sieht eine Meldung (nie
    500; geloggt werden nur Status, Fehlertyp und -code, nie Schlüssel
    oder Stripes Fehlertext). Höchstens 5 offene Bestellungen je 15
    Minuten. Die Bestellseite zeigt „warte auf Bestätigung“ (lädt alle 5 s
    neu) bis zum Webhook, dann die gutgeschriebene Menge.
  - Webhook `POST /stripe/webhook` (`routes/webhooks.php`, eigene
    Middleware-Gruppe `webhook` ohne Session, Cookies, CSRF und Login, vor
    der Catch-all-Route des Spiels; zusätzlich in der CSRF-Ausnahme).
    `StripeSignature` prüft `Stripe-Signature` (`t=…,v1=…`, HMAC-SHA256
    über `"{t}.{Body}"`, `hash_equals`, mehrere `v1` erlaubt, älter als 5
    Minuten abgelehnt) → sonst 400. `StripeWebhookHandler`: nennt die
    Session ein Universum, wird es per `InstanceRegistry` gesucht und mit
    `InstanceContext::runIn()` aktiviert, bevor die Spiel-Datenbank
    angefasst wird (unbekannt → 400 und Log). `checkout.session.completed`
    (bezahlt) und `…async_payment_succeeded`: in einer Transaktion wird die
    Bestellung nur bezahlt, wenn sie offen ist und Session, Betrag und
    Währung passen, und nur dann `premium_dark_matter` erhöht; doppelt
    gesendete Ereignisse schreiben nichts gut. `…expired` und
    `…async_payment_failed`: offene Bestellung → `expired`.
    `charge.refunded` (nur volle Erstattung): Bestellung → `refunded`,
    abgezogen wird, was der Spieler noch hat (nie unter 0); da die Charge
    die Metadaten nicht sicher trägt, wird das Universum notfalls über die
    Payment-Intent-ID in allen Universen gesucht. Teil-Erstattungen bleiben
    beim Admin.
  - Admin → Premium: Auswahl Pay2Win/Play2Win (bei mehreren Universen nur
    angezeigt, dort setzt sie die Kommandozeile), Shop-Status und Seite
    „Bestellungen“ (`/admin/premium/orders`: die letzten 200 mit Spieler,
    Paket, Menge, Betrag, Status, Zeiten; Umsatz der bezahlten je Währung).
  - Texte in allen 10 Sprachen (`game/darkmatter.php`, `admin/premium.php`).
  - Einrichtung für den Betreiber: (1) Stripe-Dashboard → Entwickler →
    API-Schlüssel: geheimen Schlüssel als `STRIPE_SECRET` in die `.env`
    (`sk_test_…` zum Testen, `sk_live_…` live). (2) Entwickler → Webhooks →
    Endpunkt `https://<Domain>/stripe/webhook` mit den Ereignissen
    `checkout.session.completed`, `checkout.session.expired`,
    `checkout.session.async_payment_succeeded`,
    `checkout.session.async_payment_failed` und `charge.refunded`; dessen
    Signing-Secret (`whsec_…`) als `STRIPE_WEBHOOK_SECRET`. Ein Endpunkt
    reicht für alle Universen. (3) Optional `STRIPE_CURRENCY`, Pakete in
    `config/premium.php` anpassen (neue Preise = neue Paket-ID).
    (4) `php artisan config:clear` bzw. Konfiguration neu cachen,
    Migrationen ausführen. (5) Im Universum `premium_mode` auf `pay2win`
    (Admin → Premium bzw. Kommandozeile). Lokal testen: `stripe listen
    --forward-to localhost/stripe/webhook` liefert ein eigenes `whsec_…`.
  - ⚠️ Wer Dunkle Materie für echtes Geld verkauft, braucht die
    Rechtstexte: Impressum, AGB, Datenschutzerklärung (Stripe als
    Auftragsverarbeiter) und eine Widerrufsbelehrung für digitale Inhalte
    (inkl. Zustimmung zum vorzeitigen Erlöschen des Widerrufsrechts vor dem
    Kauf). Sie sind nicht Teil dieser Änderung.
  - Tests: `StripeSignatureTest` (gültig, verändert, falsches Secret,
    alter/zukünftiger Zeitstempel, mehrere `v1`), `StripeWebhookControllerTest`
    (Route ohne Session/CSRF, einmalige Gutschrift bei Wiederholung,
    falscher Betrag/Währung/Session, unbezahlt → async, abgelaufen,
    Erstattung nie unter 0, falsche/alte Signatur, ohne Secret, unbekanntes
    Universum), `DarkMatterShopControllerTest` (Regeln Pay2Win/Play2Win/
    ohne Schlüssel, Pakete, Checkout mit `Http::fake` inkl. aller Felder und
    Universum in den Metadaten, Fremdpreise ignoriert, Stripe-Fehler und
    nicht erreichbar → Meldung, Bestell-Limit, fremde Bestellung),
    `PremiumOrdersTest` (Admin-Liste, Umsatz, Modus speichern), auf MariaDB
    `DarkMatterShopMysqlTest` (ganzer Kauf über die Spielseiten bis zur
    Gutschrift, geschlossener Shop) und `StripeWebhookUniverseMysqlTest`
    (Webhook schreibt im Universum `xgp_test_shop` gut, gleiche
    Bestellnummer in der Basis bleibt unberührt; unbekanntes Universum;
    Erstattung findet das Universum). Im Browser (eigener Server, Spieler
    `spieler3`): ohne `STRIPE_SECRET` kein Button und die Shop-Seite lehnt
    ab; mit Test-Schlüsseln Button und Paketliste (1,99 € … 34,99 €);
    Webhook mit falscher Signatur → 400 ohne Session-Cookie.
- ✅ **Universum in Mail-Links:** Links aus Mails nennen ihr Universum
  (`?universe=<slug>`): „Passwort vergessen“, Willkommensmail und
  Rundmails. Die Middleware nimmt das Universum aus dem Link vor dem
  Cookie und merkt es sich wie eine Wahl in der Lobby. Vorher landete ein
  Spieler, der den Reset-Link auf einem anderen Gerät öffnete, in der
  Lobby und konnte den Code im falschen Universum suchen. Test in
  `InstancesMysqlTest` (Link wählt, Reset-Mail trägt das Universum,
  unbekanntes Universum führt zur Lobby).
- ✅ **Backups je Universum:** Das automatische Backup sicherte schon die
  Datenbank des aktiven Universums, aber alle unter demselben Namen
  (`APP_NAME`) im selben Ordner; das Aufräumen alter Sicherungen hätte
  so Backups anderer Universen löschen können. `InstanceContext` hängt
  jetzt wie beim Cache-Präfix den Slug an `backup.backup.name` an:
  jedes Universum hat seinen eigenen Backup-Ordner und seine eigene
  Liste im Admin. Test in `InstancesMysqlTest`.
- ✅ **Bots beim Anlegen eines Universums:** `xgp:instance:create --bots=N`
  und `XGP_MONTHLY_BOTS` für den Monatsstart legen KI-Gegner an, über alle
  Galaxien verteilt, wie „Bots erzeugen“ im Admin. Vorher startete ein
  neues Universum ohne Gegner, bis der Admin sie von Hand anlegte. Test:
  Monatsstart mit drei Bots, jeder mit Heimatplanet.
- ✅ **Fehlerlog je Universum:** Jedes Universum schreibt in seine eigene
  Logdatei `storage/logs/xgproyect-<slug>.log` (Kanäle `single` und
  `daily`, beim Umschalten neu geöffnet). Die Fehlerliste im Admin, ihr
  Export und „Alle löschen“ sowie der Hinweis auf der Admin-Startseite
  betreffen nur das eigene Universum. Was ohne Universum läuft (Lobby,
  Befehle, Stripe-Webhook vor dem Umschalten), bleibt in
  `xgproyect.log`. Test in `InstancesMysqlTest`; live geprüft, dass ein
  Fehler im Universum in dessen Datei landet.
- ✅ **Betreiberhandbuch:** `docs/universes.md` beschreibt den Betrieb mit
  mehreren Universen an einer Stelle: Voraussetzungen (Datenbankrechte,
  Cron), erste Einrichtung, alle `xgp:instance:*`-Befehle mit Optionen,
  Monatsstart mit allen `XGP_MONTHLY_*`-Variablen, Stripe und WAX
  einrichten, Ergebnisse der WAX-Gutschrift, Updates, Backups, Logs,
  Mail-Links und häufige Fehler. Die README verweist darauf.
- ✅ **Zwei-Faktor-Anmeldung (TOTP), Pflicht im Admin-Bereich:**
  - *Einrichten* unter `/account/security`: QR-Code (Bibliothek
    `bacon/bacon-qr-code`, SVG) und Schlüssel zum Abtippen; eingeschaltet
    wird erst, wenn die App einen richtigen Code zeigt. Danach acht
    Wiederherstellungscodes, die nur einmal angezeigt werden. Neue Codes
    gibt es mit dem Passwort, ausschalten geht mit Passwort und Code.
  - *Anmelden:* Spiel- und Admin-Login prüfen erst das Passwort; ist die
    Zwei-Faktor-Anmeldung an, bleibt das Konto abgemeldet, bis unter
    `/account/two-factor` innerhalb von fünf Minuten ein Code der App oder
    ein Wiederherstellungscode kommt (`LoginFlow`; der Abschluss des Logins
    ist für beide Wege an einer Stelle). Jeder App-Code zählt nur einmal,
    ein Wiederherstellungscode nur einmal überhaupt, fünf Versuche pro
    Minute und Konto.
  - *Speicherung:* das Geheimnis verschlüsselt mit dem `APP_KEY`, die
    Wiederherstellungscodes als SHA-256; Migration `000037` (install und
    400). Ein neuer `APP_KEY` macht eingerichtete Apps ungültig.
  - *Admin-Bereich:* Middleware `admin.two_factor` schickt Admins ohne
    Zwei-Faktor-Anmeldung zur Einrichtung, und solange die Pflicht gilt,
    können sie sie nicht ausschalten. Abschaltbar mit
    `XGP_ADMIN_TWO_FACTOR=false`, nur für lokale Arbeit.
  - *TOTP:* eigene Umsetzung nach RFC 6238 (`App\Services\Auth\Totp`),
    geprüft gegen die Testvektoren des RFC.
  - *Texte:* alle Texte in den zehn Sprachen.
  - *Tests:* `TotpTest` und `TwoFactorTest`:
    - Einrichten mit falschem und richtigem Code, Geheimnis verschlüsselt;
    - Spiel-Login wartet auf den Code, derselbe Code nicht zweimal;
    - Wiederherstellungscode einmal;
    - Versuchslimit und Ablauf nach fünf Minuten;
    - Ausschalten, neue Codes;
    - Pflicht im Admin-Bereich mit Admin-Login über den Code;
    - Login ohne Zwei-Faktor unverändert.

    Gegenproben: ohne den Schutz vor doppelt benutzten Codes bzw. ohne das
    Abmelden bis zur Code-Eingabe schlagen die Tests fehl.
  - *Live geprüft:* Admin-Login führt zur Einrichtung, Einschalten mit dem
    Code der App, acht Codes, zweiter Login fragt den Code ab, falscher
    Code abgewiesen, richtiger führt in den Admin-Bereich.
- ✅ **Zwei-Faktor-Anmeldung für Spieler:**
  - Die Einstellungen im Spiel haben einen Abschnitt
    „Zwei-Faktor-Anmeldung“ mit Status (an/aus) und Link zur Einrichtung.
  - Der Shop für Dunkle Materie weist Spieler ohne
    Zwei-Faktor-Anmeldung vor dem Kauf darauf hin.
  - Für Spieler bleibt sie freiwillig.
  - Texte in zehn Sprachen; Test im `DarkMatterShopControllerTest` (Hinweis
    ohne, kein Hinweis mit Zwei-Faktor-Anmeldung).
- ✅ **Saisonlaufzeit:** Ein Universum kann ein Saisonende haben
  (`ends_at` in der Registry, zentrale Migration `000003`).
  - *Festlegen:* `xgp:instance:create --ends="2026-12-01"` oder
    `--ends="+1 month"`, ändern mit `xgp:instance:update --ends=…|none`.
    Der Monatsstart setzt `XGP_MONTHLY_DURATION` (Standard „1 month“): eine
    Saison läuft, bis die nächste beginnt.
  - *Nach dem Ende* kommt niemand mehr hinein (`Instance::isOpen`), und
    `xgp:instance:close-ended` schließt das Universum (Scheduler alle fünf
    Minuten); dort setzt später das Einfrieren der Rangliste an.
  - *Anzeige:* „Endet am …“ in der Lobby und auf der Startseite des
    Universums, „Saisonende“ in der Übersicht im Spiel; Texte in zehn
    Sprachen.
  - *Tests in `InstancesMysqlTest`:*
    - Ende setzen und anzeigen;
    - ein Ende vor dem Start wird abgewiesen;
    - Sperre nach Ablauf;
    - Schließen durch den Befehl, nur einmal;
    - Ende entfernen;
    - Laufzeit beim Monatsstart.
- ✅ **Rangliste zum Saisonende und Ruhmeshalle:** Schließt ein
  Universum (Saisonende, `--status=closed` oder `keep_open` beim
  Monatsstart), berechnet `SeasonResults` die Statistik ein letztes Mal
  und speichert die Top 10 jeder Wertung in der Basisdatenbank
  (`season_results`, zentrale Migration `000004`).
  - *Wertungen:* Gesamt, Gebäude, Forschung, Flotte, Verteidigung,
    Militär.
  - Bots und Admins zählen nicht, wie in der Highscore
    (`stat_admin_level`).
  - Die Ergebnisse bleiben, auch wenn die Universums-Datenbank später
    gelöscht wird; sie sind die Grundlage für die Belohnungen der Top 10.
  - *Lobby:* zeigt bei beendeten Universen eine aufklappbare Ruhmeshalle
    mit Namen, Allianz-Tag und Punkten; Texte in zehn Sprachen.
  - *Test in `InstancesMysqlTest`:* zwei Spieler mit unterschiedlichen
    Minen, zwei Bots und ein Admin. Nach dem Schließen gilt die richtige
    Reihenfolge in Gesamt und Gebäuden, ohne Bots und Admin, und die
    Lobby zeigt die Ruhmeshalle.
- ✅ **Saisonpreise (gestaffelt, mehrere Wertungen):** Jede Wertung hat
  einen Topf Dunkler Materie, den die Top 10 gestaffelt teilen.
  - *Standard* (`config/seasons.php`, `XGP_SEASON_REWARDS` und
    `XGP_SEASON_SHARES`): Gesamt 100.000, Gebäude, Forschung, Flotte,
    Verteidigung und Militär je 20.000; Plätze 1–10 erhalten
    30/20/15/10/7/6/4/3/3/2 %.
  - *Je Universum gespeichert:* Jedes Universum behält den Plan, mit dem
    es angelegt wurde (`reward_plan` in der Registry, zentrale Migration
    `000005`). Ändern mit
    `xgp:instance:update --rewards=total=…,military=… --shares=60,40`.
    Abgewiesen werden mehr als zehn Plätze, zusammen über 100 % und
    unbekannte Wertungen.
  - *Beim Einfrieren* bekommt jeder Platz seinen Preis (`reward` in
    `season_results`).
  - *Lobby:* zeigt bei offenen Universen die Saisonpreise, in der
    Ruhmeshalle steht der Preis neben jedem Platz.
  - *Tests:* `RewardPlanTest` (Staffel, Summe, Grenzen, gespeicherte
    Form). `InstancesMysqlTest`: Plan aus der Konfiguration, eigener Plan
    per Befehl, ungültige Staffel abgewiesen, Preise nach dem Schließen,
    Anzeige in Lobby und Ruhmeshalle.
- ✅ **WAX-Konto über alle Universen, Saisonpreise einlösen:**
  - *Verknüpfen* unter `/account/wax` (auch aus den Einstellungen und, bei
    laufender Saison ohne Verknüpfung, mit Hinweis in der Übersicht): Der
    Spieler überweist eine Kleinstmenge WAX mit dem Memo
    `xgp-link:<code>` an das Shop-Konto. `xgp:wax:check` verknüpft den
    Absender (Ergebnis „linked“ in der Admin-Liste). Ein neuer Code macht
    den alten ungültig; ein anderes Konto verknüpft man mit einer
    weiteren Überweisung.
  - *Speicherung:* zentral in `wax_links` (Universum, Spieler, Code,
    WAX-Konto; zentrale Migration `000006`). Das ist die Identität über
    die getrennten Konten der Universen.
  - *Beim Einfrieren* der Rangliste bekommt jeder Platz das verknüpfte
    WAX-Konto (`season_results.wax_account`); ohne Verknüpfung bleibt der
    Preis beim Admin.
  - *Einlösen:* In einem Pay2Win-Universum sieht ein Spieler mit
    demselben WAX-Konto die offenen Preise anderer Universen und löst sie
    als Dunkle Materie ein (`SeasonRewards`). Jeder Preis zählt einmal,
    nur für sein WAX-Konto; kann nicht gutgeschrieben werden, wartet er
    wieder. In Play2Win-Universen ist Einlösen nicht möglich.
  - *Texte:* alle Texte in zehn Sprachen.
  - *Tests:*
    - `WaxPaymentsTest`: Link-Memo verknüpft, unbekannter Code nicht.
    - `WaxControllerTest`:
      - Memo ohne Verknüpfung, neuer Code;
      - Einlösen einmal, fremde Preise nicht;
      - nur in Pay2Win;
      - Preis wartet wieder ohne Gutschrift.
    - `InstancesMysqlTest`: WAX-Konto des verknüpften Gewinners in der
      eingefrorenen Rangliste.
- ✅ **Betrugserkennung (Mehrfachkonten, Pushing, Skripte):**
  - *Protokoll* je Universum (Migration `000038`, install und 400):
    - Logins mit IP, Browser und einem Geräte-Cookie `xgp_device`
      (`LoginFlow`);
    - Rohstofflieferungen an andere Spieler (`Transport`);
    - Spielanfragen pro Stunde (Middleware `TrackActivity`).

    Alles älter als 60 Tage wird gelöscht.
  - *Prüfung* `xgp:cheat:scan` (stündlich, `config/cheats.php`) legt
    Hinweise an:
    - gleicher Browser (Stärke 80) oder gleiche IP (40);
    - netto über 100.000 Rohstoffe an einen Stärkeren (60; 90, wenn die
      beiden auch Browser oder IP teilen), für beide Seiten;
    - an drei Tagen der Woche je 20 Stunden aktiv (50);
    - mehr als 1.200 Anfragen in einer Stunde (50).

    Die eigenen KI-Bots zählen nicht. Ein erneut gefundener Hinweis behält
    die Entscheidung des Admins.
  - *Admin → Information → Verdachtsfälle* (Berechtigung `suspicions`,
    Migration `000039`): Hinweise mit Belegen und Markierung für die
    Top 10, Entscheidung „offen“, „in Ordnung“ oder „ausgeschlossen“ mit
    Notiz.
  - *Ausschluss:* „Ausgeschlossen“ nimmt den Spieler beim Saisonende aus
    allen Wertungen und Preisen.
  - *Texte:* alle Texte in zehn Sprachen.
  - *Tests:*
    - `CheatScannerTest`:
      - gemeinsamer Browser und gemeinsame IP, Bots und alte Logins
        außen vor;
      - Pushing einschließlich Gegenrichtung, Schwächere, eigene
        Planeten und Verstärkung durch gemeinsamen Browser;
      - Aktivität rund um die Uhr und Skripttempo;
      - Entscheidung bleibt, Löschen alter Daten.
    - `SuspicionsControllerTest` (Liste, Entscheidung, ungültiger
      Status).
    - `TwoFactorTest`: Login protokolliert, Geräte-Cookie gesetzt.
    - `TransportTrailMysqlTest`: Lieferung an andere protokolliert, an
      sich selbst nicht.
    - `SuspicionsMysqlTest`: Prüfung und Liste mit Tabellenpräfix.
    - `InstancesMysqlTest`: ausgeschlossener Spieler fehlt in der
      Rangliste, obwohl er die meisten Punkte hat.
  - *Gefunden beim Crawl:* Die Sortierung der Liste nutzte einen
    Tabellen-Alias in rohem SQL, der mit dem Präfix `xgp_` unter MariaDB
    nicht stimmte (500). Jetzt wird in PHP sortiert, und der MariaDB-Test
    deckt das ab.
- ✅ **Zahlungsarten je Universum und Zahlung mit WAX:** Jedes Universum
  legt fest, wie Dunkle Materie bezahlt wird: Option `payment_methods`
  (`stripe`, `wax`, beides oder nichts). Der Shop öffnet nur mit einer
  Zahlungsart, die das Universum erlaubt **und** der Server eingerichtet
  hat (Stripe-Schlüssel bzw. `XGP_WAX_ACCOUNT`). Play2Win zeigt nie einen
  Kauf-Button.
  - *Festlegen:* `xgp:instance:create --payments=stripe,wax`,
    `xgp:instance:update --payments=wax|none`, `XGP_MONTHLY_PAYMENTS` für
    den Monatsstart, im Admin unter Premium als Häkchen (mit Hinweis, was
    in der `.env` fehlt). Die Lobby zeigt bei Pay2Win die Zahlungsarten.
  - *Preis:* Die Pakete bleiben in Euro; der WAX-Preis wird zum aktuellen
    Kurs umgerechnet (CoinGecko, 5 Minuten gecacht, optional
    `XGP_WAX_MARKUP` Prozent Aufschlag) und auf 0,01 WAX aufgerundet.
    Das Angebot gilt 30 Minuten.
  - *Kauf:* Der Spieler wählt „Mit WAX zahlen“ und sieht Konto, genauen
    Betrag und Memo (`xgp:<universum>:<bestellung>`, ohne Universen
    `xgp:<bestellung>`), zum Beispiel für WAX Cloud Wallet oder Anchor. Die
    Seite lädt sich alle 30 Sekunden neu, bis gutgeschrieben ist. Ist der
    Kurs nicht abrufbar, wird nichts bestellt.
  - *Gutschrift:* `xgp:wax:check` läuft per Scheduler jede Minute. Er liest
    die Überweisungen an das Shop-Konto von einem Hyperion-Server
    (Standard `wax.eosusa.io`, `XGP_WAX_API`) und verbucht jede genau
    einmal, aber erst wenn ihr Block unumkehrbar ist.
    - Es zählt nur echtes WAX (`eosio.token`, 8 Nachkommastellen). Ein
      Fremd-Token, das sich „WAX“ nennt, zählt nicht.
    - Das Universum aus dem Memo wird aktiviert, dort wird die Bestellung
      gutgeschrieben.
    - Ergebnisse: gutgeschrieben, zu spät, zu wenig, Bestellung nicht
      offen (z. B. doppelt bezahlt), unbekanntes Memo, Spieler gelöscht.
      Alles außer der Gutschrift entscheidet der Admin: von Hand
      gutschreiben oder zurücksenden.
    - Die Überweisungen stehen in `wax_transfers` in der Basisdatenbank
      (zentrale Migration) und erscheinen in der Admin-Bestellliste. Der
      nächste Lauf macht dort weiter, wo der letzte aufgehört hat.
  - *Schema:* Migration `000035` (install und 400) ergänzt
    `dark_matter_orders` um `provider_amount`, `provider_currency` und
    `expires_at`. Migration `000036` legt die Option `payment_methods`
    (Standard `stripe`) an.
  - *Tests:*
    - `WaxChainTest`: Lesen, Fremd-Token, exakte Beträge, Anfrage an
      Hyperion, Fehler.
    - `WaxRatesTest`: Umrechnung, Aufrunden, Aufschlag, Cache, fehlender
      Kurs.
    - `WaxPaymentsTest`:
      - einmalige Gutschrift;
      - Warten auf den unumkehrbaren Block;
      - alle Ergebnisarten einschließlich ausgehender Überweisungen und
        Fremd-Token;
      - Fortsetzen und Seitenweise lesen.
    - Shop-Tests für Zahlungsarten, WAX-Kauf, Ablauf und fehlenden Kurs.
    - Admin-Tests für Häkchen und die Überweisungsliste.
    - `WaxPaymentsUniverseMysqlTest`: Gutschrift nur im Universum des
      Memos.
    - Befehle: `--payments`, Prüfung und `none`.
    - Lobby: Anzeige der Zahlungsarten.
    - Mutationsproben: ohne die Prüfung des Token-Vertrags bzw. des
      Empfängers schlagen die Tests fehl.
  - *Live geprüft:*
    - Shop und Bestellseite mit echtem Kurs (1,99 € ≈ 471,92 WAX).
    - `xgp:wax:check` gegen die echte Kette (ein aktives Fremdkonto):
      340 bzw. 418 Überweisungen gelesen, alle korrekt als unbekannt
      verbucht; der zweite Lauf setzte nahtlos fort.
    - Crawl aller Seiten ohne neue Treffer.
- ✅ **Eigener Token: Shop zum festen Kurs, Marktpreis, Preisauszahlung:**
  - *Token:* `config/premium.php` → `token` (`XGP_TOKEN_CONTRACT`,
    `XGP_TOKEN_SYMBOL`, `XGP_TOKEN_DECIMALS`, `XGP_TOKEN_DARK_MATTER`,
    `XGP_TOKEN_MARKET_URL`). `TokenSpec` beschreibt einen Token (Vertrag,
    Symbol, Nachkommastellen; `wax()` und `game()`) mit exakten Mengen.
  - *Shop:* neue Zahlungsart `token` (je Universum wie `stripe`/`wax`;
    eingerichtet, wenn Shop-Konto und Token gesetzt sind). Preis fest:
    Dunkle Materie ÷ Kurs, aufgerundet (`TokenRates`). Daneben der
    Marktwert in WAX aus Alcor (`system_price`, 5 Minuten gecacht). Die
    Bestellung gilt 24 Stunden; Bestellseite mit Konto, Menge und Memo.
  - *Gutschrift:* `xgp:wax:check` fragt Hyperion nach beiden Verträgen
    (`eosio.token:transfer,<vertrag>:transfer`). `WaxChain::transfer`
    nimmt nur bekannte Paare aus Vertrag und Symbol mit genau ihren
    Nachkommastellen. Eine Bestellung wird nur in ihrem eigenen Symbol
    bezahlt (`provider_currency`). Zentrale Migration `000007` speichert
    das Symbol jeder Überweisung; die Admin-Liste zeigt Beträge im
    richtigen Token.
  - *Preisauszahlung:* `xgp:season:payout <universum>` reserviert die nicht
    eingelösten Preise verknüpfter WAX-Konten (`claimed_universe =
    payout`, damit nicht doppelt als Dunkle Materie eingelöst) und gibt
    die `transfer`-Aktionen als JSON zum Unterschreiben in der Wallet aus.
    Der Server hält keinen Schlüssel. Eine ausgehende Token-Überweisung
    mit Memo `xgp-prize:<id>` an das hinterlegte Konto markiert den Preis
    als ausgezahlt (`token`, Ergebnis „paid_out“). `--release` gibt
    reservierte Preise frei. Nur für beendete Universen.
  - *Texte:* alle Texte in zehn Sprachen; Handbuch `docs/universes.md`
    (Eigener Token, Preise im eigenen Token auszahlen), `.env.example`.
  - *Tests:*
    - `TokenTest`: Konfiguration, exakte Mengen, fester Kurs
      (Shop aufgerundet, Preis abgerundet), Marktpreis mit Cache und
      Fehler, nur der eigene Vertrag, Hyperion-Filter.
    - `WaxPaymentsTest`:
      - Token-Bestellung nur mit dem Token, WAX-Bestellung nicht mit
        dem Token, Fremdvertrag, zu wenig;
      - Auszahlung: reservieren, Ausgabe, nur das hinterlegte Konto
        und nur im Token, `--release`;
      - laufende Saison und unbekanntes Universum abgelehnt.
    - `DarkMatterShopControllerTest`: Token-Preis, Marktwert,
      Bestellung, Bestellseite, Zahlungsart verschwindet ohne Vertrag.
    - Mutationsproben: ohne Symbolprüfung der Bestellung, ohne
      Empfängerprüfung oder ohne Symbolprüfung der Auszahlung schlagen
      die Tests fehl.
- ✅ **Betreiberbereich `/operator` mit Pflicht-2FA:** die Universen im
  Browser verwalten, getrennt von allen Universen.
  - *Konten:* `operators` in der Basisdatenbank (zentrale Migration
    `000008`, Modell `Operator`, Guard `operator`). Angelegt werden sie nur
    auf der Kommandozeile: `xgp:operator:create` fragt das Passwort
    (mindestens 12 Zeichen), zeigt den QR-Code im Terminal und speichert
    das Konto erst, wenn die App den richtigen Code zeigt. Dazu
    `xgp:operator:reset` (Passwort, neue App), `xgp:operator:list` und
    `xgp:operator:delete`. Die Zwei-Faktor-Anmeldung wird nie im Browser
    eingerichtet: Ein Passwort allein kann keine eigene App eintragen.
  - *Anmeldung* (`OperatorLogin`): Passwort, dann immer TOTP- oder
    Wiederherstellungscode (`TwoFactor` für Spieler und Betreiber).
    - Ohne Zwei-Faktor-Anmeldung kein Zugang; unbekannte E-Mail und
      falsches Passwort geben dieselbe Antwort und dauern gleich lang.
    - Jeder Code gilt einmal; nach fünf falschen Codes zurück zum
      Passwort.
    - Rate-Limit: 10 pro Minute je Adresse, 5 je Konto.
  - *Rahmen* (`OperatorArea`):
    - eigene Sitzung (Dateien, Cookie `xgp_operator`, `SameSite=strict`,
      30 Minuten Leerlauf); `AuthenticateOperator` meldet spätestens nach
      8 Stunden ab;
    - Header `X-Frame-Options: DENY`, `no-store`, `no-referrer`;
    - optional nur von `XGP_OPERATOR_IPS` (Adressen oder Netze, sonst
      404); `XGP_OPERATOR_WEB=false` schaltet den Bereich ab.
  - *Seiten:*
    - Universenliste mit Protokoll;
    - Universum anlegen (Datenbank, Zahlungsarten, Saisonende, KI-Spieler,
      Admin des Universums);
    - bearbeiten (Name, Modus, Status, Zahlungsarten mit Hinweis auf
      fehlende `.env`-Werte, Saisonende, Preise und Anteile);
    - schließen mit Rückfrage;
    - Saison mit allen Preisen und ihrem Stand (offen, eingelöst in,
      reserviert, ausgezahlt) und Token-Auszahlung wie
      `xgp:season:payout`, mit fertigem JSON für die Wallet;
    - Konto: Passwort ändern und neue Wiederherstellungscodes, beides nur
      mit aktuellem Passwort und Code; die Codes werden einmal angezeigt.
  - *Protokoll:* `operator_events` (wer, was, welches Universum, Adresse,
    Zeit) für Anmeldungen, auch fehlgeschlagene, falsche Codes, jede
    Änderung, Auszahlungen und die Kontobefehle (Adresse `cli`).
  - *Sprache:* aus dem Browser oder `?lang=`; alle Texte in zehn Sprachen
    (`operator/operator.php`). Eigenes schlichtes Layout, hell und dunkel,
    auch auf dem Handy ohne seitliches Scrollen.
  - *Tests:*
    - `OperatorLoginTest` (SQLite):
      - Passwort und Code;
      - gleiche Antwort für unbekannt und falsch;
      - ohne 2FA kein Zugang;
      - Code und Wiederherstellungscode je einmal;
      - Versuchslimit und Höchstdauer;
      - IP-Sperre, gefälschtes `X-Forwarded-For` hilft nicht,
        Abschalten;
      - eigene Sitzung;
      - Passwortwechsel und neue Codes nur mit Passwort und Code;
      - Sprache.
    - `OperatorCommandsTest`: Konto erst nach richtigem Code, falscher
      Code legt nichts an, kurze Passwörter, neue App behält bis zur
      Bestätigung die alte, Liste und Löschen.
    - `OperatorUniversesMysqlTest`: Universum im Browser anlegen (echte
      Datenbank), ändern, schließen, Protokoll; Token-Auszahlung und
      Freigabe, laufende Saison abgelehnt.
    - Mutationsproben (sieben): ohne 2FA-Pflicht, ohne Höchstdauer, ohne
      IP-Sperre, Login schon nach dem Passwort, ohne Versuchslimit,
      Passwortwechsel ohne altes Passwort, Konto ohne Codebestätigung;
      jedes Mal schlagen die Tests fehl.
  - *Live geprüft:* Konto über ein echtes Terminal angelegt (QR-Code,
    Code, Wiederherstellungscodes), im Browser angemeldet, falsches
    Passwort, Universum mit eigener Datenbank angelegt, geändert,
    geschlossen, Protokoll, Abmelden, Handyansicht. Danach aufgeräumt.
- ✅ **Sicherheits-Audit (Geldflüsse zuerst):** Stripe, WAX und Token,
  Bestellungen, Preise und Auszahlung, Anmeldung und 2FA, Admin- und
  Betreiberbereich, Spiel-Eingaben, Konfiguration und Abhängigkeiten geprüft
  (`composer audit` und `npm audit` ohne Befunde). Gefunden und behoben:
  - *Hoch: WAX-Verknüpfung kapern.* Der Code galt nach dem Verknüpfen
    weiter, das Memo steht öffentlich auf der Chain. Jeder konnte es noch
    einmal senden und die Verknüpfung (und damit die Saisonpreise) auf
    sein Konto lenken. Jetzt bekommt die Verknüpfung nach dem ersten
    Gebrauch einen neuen Code; zentrale Migration `000009` erneuert die
    Codes bestehender Verknüpfungen.
  - *Hoch: Reset-Link über den Host-Header.* Der Link in der „Passwort
    vergessen“-Mail kam aus dem `Host` der Anfrage; ein Angreifer konnte
    ihn für fremde Konten auf seinen Server zeigen lassen (Kontoübernahme
    mit einem Klick). Jetzt immer `APP_URL`.
  - *Hoch: ein einziger Hyperion-Server als Wahrheit.* Ein falscher oder
    übernommener Server hätte Zahlungen erfinden können. Jetzt bestätigt
    ein zweiter Server eines anderen Betreibers jede Überweisung, die
    etwas bewirkt (`WaxVerifier`, `XGP_WAX_VERIFY_API`, Standard
    `api.waxsweden.org`): gleiche Aktion, gleicher Inhalt, dort ebenfalls
    unumkehrbar. Kennt er sie noch nicht, wird gewartet; widerspricht er
    oder kennt er sie nach 30 Minuten nicht, zählt sie nicht (Ergebnis
    „unconfirmed“ für den Admin).
  - *Hoch: Passwörter ohne Versuchsgrenze.* Spiel-Login, Admin-Login,
    Registrierung und „Passwort vergessen“ hatten kein Limit. Jetzt 5 pro
    Minute und Konto, 20 pro Adresse; Registrierung 5 pro Stunde und
    Adresse; Reset-Mails 5 pro Stunde und Adresse. Zwei-Faktor-Code nach
    fünf Fehlern 15 statt 1 Minute gesperrt.
  - *Mittel: doppelt ausgeben.* Offiziere und das Auffüllen beim Händler
    zogen Dunkle Materie ohne Bedingung ab: Zwei gleichzeitige Anfragen
    zahlten aus demselben Guthaben, das ins Minus ging. Jetzt nur, wenn
    sie im selben Moment noch da ist (Offiziere zusätzlich nur für die
    gelesene Laufzeit).
  - *Mittel: Rückbuchungen.* Ein Chargeback (`charge.dispute.created`)
    ließ die Dunkle Materie beim Spieler. Jetzt wird sie wie bei einer
    Erstattung zurückgenommen; Status „disputed“.
  - *Mittel: Kursquelle ohne Prüfung.* Ein falscher WAX-Kurs hätte Dunkle
    Materie für 30 Minuten verschleudert. Jetzt wird ein Kurs, der mehr als
    `XGP_WAX_MAX_RATE_JUMP` Prozent (Standard 50) vom letzten abweicht,
    abgelehnt, bis ein Tag vergangen ist.
  - *Mittel: Insider.* Setzt ein Admin eines Universums die Dunkle Materie
    eines Spielers, steht das jetzt mit Admin, Spieler, altem und neuem
    Wert im Protokoll der Basisdatenbank (`/operator`), wo der Admin es
    nicht löschen kann; ohne Universen in der Logdatei.
  - *Niedrig:* globale Header `X-Frame-Options: SAMEORIGIN` (keine
    Klick-Falle auf Kauf-Buttons), `nosniff`, `Referrer-Policy`, HSTS über
    HTTPS; `SESSION_SECURE_COOKIE=true` in `.env.example`; Cookie von
    `/operator` über HTTPS nur verschlüsselt; Planetennamen-Regex `A-z`
    ließ `[ \ ] ^` und Backtick zu.
  - *Geprüft ohne Befund:* Stripe-Signatur (zeitkonstant, 5 Minuten),
    Gutschrift einmalig unter Sperre mit Betrag, Währung und Session;
    WAX-Gutschrift erst unumkehrbar, Vertrag und Symbol geprüft; Preise
    einlösen atomar; alle Admin-Seiten hinter Anmeldung, 2FA und Rechten;
    Installer nach der Installation gesperrt; Backups außerhalb von
    `public/` mit strengem Dateinamen; keine Geheimnisse im Repository;
    rohes SQL nur mit Zahlen oder Positivlisten; Namen ohne HTML-Zeichen;
    Flottendaten aus der Sitzung.
  - *Tests:*
    - `WaxPaymentsTest`: zweiter Server widerspricht, kennt die
      Überweisung nie, ist noch nicht unumkehrbar, ist nicht erreichbar;
      Link-Code zählt einmal.
    - `ConcurrentSpendingMysqlTest` (MariaDB): eine andere Anfrage gibt
      die Dunkle Materie genau zwischen Prüfung und Abbuchung aus
      (Offizier, doppelter Offizier, Auffüllen).
    - `SecurityHardeningTest`: Login-, Registrierungs- und Reset-Limits,
      Reset-Link trotz fremdem Host, Header.
    - `StripeWebhookControllerTest`: Chargeback.
    - `WaxRatesTest`: Kurssprung.
    - `UserEditAllowlistTest`: Admin-Protokoll.
    - Mutationsproben (14): Jeder Fix wurde einzeln ausgebaut, jedes Mal
      schlugen die Tests fehl.
  - *Live geprüft:* eine echte WAX-Überweisung vom ersten Server wurde vom
    zweiten (`api.waxsweden.org`) bestätigt, dieselbe mit verfälschtem
    Betrag abgelehnt, eine erfundene nicht gefunden.
- ✅ **Spielverlauf: Rohstoffe und Schiffe vermehren durch gleichzeitige
  Aufrufe.** Jeder Seitenaufruf jedes Spielers verarbeitet fällige Flotten,
  Bauaufträge und die Produktion. Zwei Aufrufe im selben Moment sahen
  dasselbe und handelten beide; Werte, die zu Beginn eines Aufrufs gelesen
  wurden, wurden später absolut zurückgeschrieben. Gefunden und behoben:
  - *Flotten zweimal verarbeitet* (Landen, Liefern, Stationieren, Plündern,
    Recyceln, Expeditionen): `MissionControlLib` sperrt jede fällige Flotte
    in einer eigenen Transaktion und liest sie neu. Hat ein anderer Aufruf
    sie schon verarbeitet (Zustand geändert oder gelöscht), wird sie
    übersprungen; Deadlocks werden bis zu dreimal wiederholt, ein Fehler
    bricht nur diese Mission ab, nicht die Seite.
  - *Zwei Flotten auf dasselbe Ziel* (doppeltes Recycling, doppelte
    Plünderung): Zielplanet, Trümmerfeld und Trümmerfeld im tiefen Raum
    werden innerhalb der Mission mit `FOR UPDATE` gelesen; die zweite Flotte
    wartet und sieht, was die erste übrig ließ.
  - *Produktion schrieb absolut zurück:* Rohstoffe und gebaute Schiffe
    wurden mit den beim Lesen bekannten Werten gesetzt und hoben einen
    gleichzeitigen Flottenstart, Kauf oder Bau auf (Rohstoffe und Schiffe
    verdoppelt). Jetzt nur, wenn `planet_last_update` und die
    Werft-Warteschlange noch die gelesenen sind, und nur als Zuwachs;
    sonst liest der Aufruf die gespeicherten Werte neu.
  - *Abzug ohne Bedingung:* Flottenstart, Sonden aus der Galaxie,
    Interplanetarraketen und Sprungtor prüften vorher und zogen dann ohne
    Bedingung ab; zwei Aufrufe schickten dieselben Schiffe zweimal.
    `PlanetStock::take()` zieht Schiffe und Rohstoffe in einer Anweisung
    nur ab, wenn alles noch da ist, sonst wird die Aktion zurückgerollt
    (`NoLongerOnPlanet`, Meldung `fl_gone_meanwhile` in zehn Sprachen).
    Das Sprungtor springt zusätzlich nur einmal pro Aufladung.
  - *Bau, Forschung, Werft, Händler-Auffüllen bezahlten absolut:* Kosten
    und Erstattungen gehen jetzt relativ und bedingt über
    `PlanetStock::spend()/refund()`; das Modell wird danach ohne
    Änderungsmarke abgeglichen, sodass ein `save()` keine alten Rohstoffe
    zurückschreibt. Erstattet wird nur, wer den Auftrag tatsächlich löscht.
  - *Stufen zweimal erhöht:* Fertige Gebäude und Forschungen werden durch
    atomares Löschen des Auftrags beansprucht und relativ um eins erhöht.
  - *Forschung aus der Warteschlange ohne Deckung:* wurde beim Start
    bezahlt, auch wenn das Geld fehlte (Planet ins Minus, von der
    Produktion auf 0 gesetzt: gratis). Jetzt wird sie wie ein Gebäude
    verworfen.
  - *Rückruf* nur, wenn die Flotte noch im gelesenen Zustand ist.
  - *Tests:*
    - `ResourceDuplicationMysqlTest`: Rückkehr landet einmal, Trümmerfeld
      einmal geladen, Ziel bleibt gesperrt (zweite Datenbankverbindung
      wartet), Produktion hebt Abzug nicht auf, gebaute Schiffe werden
      addiert, kein doppeltes Produzieren, bedingter Abzug, Gebäude- und
      Forschungsstufe einmal, unbezahlbare Forschung verworfen,
      Erstattung einmal.
    - `ConcurrentSpendingMysqlTest` (über echte Spielanfragen): Auffüllen
      relativ, Werft bedingt, Rückruf, Sprungtor, Raketen.
    - Mutationsproben (16): Jeder Fix wurde einzeln auf das alte Verhalten
      zurückgebaut, jedes Mal schlug ein Test an.
  - *Live geprüft* gegen den Webserver mit 8 Workern und 40 parallelen
    Aufrufen in der Sekunde der Ankunft:
    - Rückkehr von 10 Recyclern: alter Code 20 bis 40 gelandet (fünf
      Läufe), neuer Code zehnmal genau 10.
    - zwei Recycler-Flotten auf 100.000 Metall Trümmer: alter Code unter
      anderem 400.000 Metall im Feld danach (300.000 aus dem Nichts), neuer
      Code fünfmal genau 100.000 geladen, Feld leer.
- ✅ **Performance-Audit mit 1.000.000 Spielern.** Eine eigene Datenbank
  mit 1 Mio. Spielern, 1,3 Mio. Planeten und Monden, 100.000 Flotten im
  Flug, 3 Mio. Nachrichten, 20.000 Allianzen und 200.000 Freundschaften
  (alle Tabellen eines Spielers gefüllt), dazu 1 Mio. Anmeldungen und
  4,8 Mio. Aktivitätsstunden für die Betrugserkennung. Jede Abfrage eines
  vollständigen Crawls (174 Formen) per `EXPLAIN` gegen diese Datenbank
  geprüft, die Seiten über den Webserver gemessen.
  - *Ein Universum fasst nicht 1 Mio. Spieler:* Neue Heimatplaneten
    kommen auf die Positionen 4, 6, 8, 10, 12, also 9 × 499 × 5 =
    22.455 Plätze. 1 Mio. Spieler heißt rund 45 Universen. Der Test
    überfüllt ein Universum (401 Galaxien) absichtlich, damit jede Tabelle
    die Größe hat, an der Abfragen ohne Index auffallen.
  - *Universum voll:* `PlanetService::isPlanetFree` rief sich für jeden
    belegten Platz selbst auf, in einem vollen Universum ohne Ende, bis PHP
    abstürzte. Jetzt eine Schleife mit einer Abfrage pro System über jedes
    System einmal; ist nichts frei, sagt die Registrierung es
    (`re_universe_full`, zehn Sprachen).
  - *Zwei Registrierungen, ein Platz:* Der Platz wurde vor der Transaktion
    gesucht; zwei gleichzeitige Anmeldungen bekamen denselben. Jetzt in der
    Transaktion, die Zeile des letzten Platzes ist gesperrt
    (`lockForUpdate`).
  - *Fehlende Indizes* (Migration `2026_10_18_000040`, idempotent, in
    `install` und `400`): `fleets` und `messages` hatten außer dem
    Primärschlüssel keinen Index, `planets` keinen auf Besitzer oder
    Koordinaten. Jeder Seitenaufruf las alle Planeten (Planeten des
    Spielers, Mond des aktuellen Planeten), alle Nachrichten (ungelesene
    zählen) und alle Flotten (fällige, eigene). Neu u. a.
    `planets(planet_user_id, planet_destroyed, planet_type)` – auch die
    Reihenfolge, in der die Statistik summiert; ein einzelner Index auf
    `planet_destroyed` lenkte den Optimierer dort auf einen fünfmal
    langsameren Plan –, `planets(Koordinaten, Typ)`, Nachrichten nach
    Empfänger/gelesen/Zeit, Flotten nach Besitzer, Ziel, Ankunft
    `(fleet_mess, fleet_start_time)`, Rückkehr, Start- und Zielkoordinaten
    und ACS-Gruppe, `users(name, ally_id, onlinetime, authlevel)`,
    Freundschaften, Spionage-Log, ACS-Mitglieder, Aktivitätsstunden und je
    Highscore-Rangliste der Rang. Auf 1 Mio. Spielern in 24 Sekunden
    angelegt.
  - *Die Statistik legte das Universum lahm:* Standard ist
    `stat_update_time = 1` (Minute). Der Neuaufbau lief im Seitenaufruf,
    mit mehreren Abfragen pro Spieler und am Ende einem einzigen INSERT mit
    allen Spielern (weit über `max_allowed_packet`) aus PHP-Arrays von
    Gigabytes. Jeder Aufruf, der ihn fällig fand, startete ihn; das
    Zeitlimit beendete ihn nach 30 Sekunden, `stat_last_update` wurde nie
    geschrieben, also begann der nächste Aufruf von vorn. Gemessen: jede
    Seite 500 nach 60 Sekunden, dauerhaft.
    - `Statistics\BulkStatistics`: Punkte rechnet die Datenbank (ein
      `INSERT … SELECT` über Planeten und Forschung, geometrische Reihe mit
      `POWER`); ein Rang ist die Position, an der ein Spieler nach Punkten
      sortiert in eine temporäre Tabelle mit Auto-Increment eingefügt wird
      (keine Fensterfunktionen, läuft also auf MySQL 5.7, MariaDB und
      SQLite). Alle zehn Ranglisten in zwei Durchgängen (alter Rang,
      neuer Rang), in Scheiben von 10.000 Spielern, damit ein
      Seitenaufruf, der die Punkte eines Spielers ändert, nur auf eine
      Scheibe wartet. Gleichstand: das ältere Konto zuerst. Allianzen
      genauso.
    - Gemessen auf 1 Mio. Spielern: 116 Sekunden, 45 MB Speicher.
    - Nur ein Seitenaufruf übernimmt ihn: `SettingsService::claim`
      verschiebt den Zeitstempel nur, wenn er noch der gelesene ist. Für
      die Dauer liegt er eine Stunde voraus (stirbt der Lauf, beginnt der
      nächste danach), am Ende auf dem Start des Laufs. Der Lauf kommt nach
      der Antwort an den Spieler (`app()->terminating`, ohne Zeitlimit;
      unter php-fpm wartet niemand darauf). Live mit 20 gleichzeitigen
      Aufrufen: genau ein Lauf.
    - Ebenso für Aufräumen, Backup und Bots (die Bots spielten bei
      gleichzeitigen Aufrufen doppelt).
  - *Spielerzahl auf jeder Seite:* Jeder Aufruf zählte alle Spieler der
    Statistik (1,3 Sekunden bei 1 Mio.). Jetzt schreibt der Neuaufbau die
    Zahl in die Option `stat_users`.
  - *bcrypt bei jedem Seitenaufruf:* Die Sitzung hielt einen bcrypt-Hash
    des Passwort-Hashes, geprüft bei jedem Aufruf: 73 ms Rechenzeit, bei
    vielen Spielern der größte Posten (5.000 Aufrufe pro Sekunde wären
    rund 365 Prozessorkerne nur dafür). Jetzt ein HMAC mit `SECRETWORD`,
    verglichen in Mikrosekunden; ein neues Passwort beendet die Sitzung
    weiterhin. Bestehende Sitzungen werden einmal auf die alte Art geprüft
    und dann umgestellt, niemand wird abgemeldet.
  - *Highscore:* sortierte jede Seite alle Spieler nach Punkten (Seite
    5.001: 5,6 Sekunden) und bot jede der 10.000 Seiten als eigene Option
    an. Jetzt nach Rang über den Index (1 ms) und rund 150 Optionen (um die
    aktuelle Seite herum, dann jede 10. und 100.). Fehler behoben: ab Rang
    10.001 sprang die Seite wieder an den Anfang. Ehrentitel zählen über
    einen Index statt über die ganze Statistik.
  - *Betrugserkennung* (stündlich): bildete für jede gemeinsame Adresse
    alle Paare. Hinter einer Mobilfunk- oder Uni-Adresse mit 5.000
    Spielern sind das 25 Mio. Paare. Alter Scan: Speicherüberlauf bei 2 GB.
    Jetzt zählen nur Browser und Adressen von 2 bis
    `XGP_CHEATS_SHARED_MAX_ACCOUNTS` (20) Konten; die Aktivität wertet die
    Datenbank aus statt einer Woche Stundenzeilen in PHP; Punkte werden nur
    für die beteiligten Spieler gelesen. Neuer Scan: 47 Sekunden, 45 MB.
  - *Admin-Listen:* Besitzer von Planet, Mond und Allianz, Gründer und
    „Spieler sperren“ listeten jeden Spieler (bei 1 Mio. rund 50 MB HTML),
    die Spielerseite jede Allianz (5,2 MB). Jetzt die ersten 500 nach Namen
    mit Suche nach dem Namensanfang, der aktuelle Besitzer immer dabei
    (`Admin\PlayerChoices`); Spielerseite 210 KB. Die Flottenliste zeigt die
    nächsten 500.
  - *Lobby:* zählte bei jedem Aufruf die Spieler jedes Universums (200 ms
    pro Universum bei 1 Mio.); die Zahl bleibt jetzt eine Minute im Cache
    des Universums.
  - *ACS:* `fleet_group` ist Text, die Gruppen-Id eine Zahl; der Vergleich
    konnte keinen Index nutzen. Jetzt als Text verglichen.
  - *Ergebnis auf 1 Mio. Spielern* (Webserver, angemeldeter Spieler):
    Übersicht vorher 40 Sekunden (vor den Indizes), mit fälliger Statistik
    jede Seite 500; nachher alle Spielerseiten 46 bis 125 ms (Übersicht,
    Galaxie, Highscore auch auf Seite 5.001, Nachrichten, Bewegungen,
    Flotte, Freunde, Suche, Imperium, Allianz, Forschung). Admin-Seiten
    unter 200 ms außer der Startseite (0,7 s, zählt Planeten und Spieler).
  - *Tests:* `BulkStatisticsTest` (Punkte wie einzeln gezählt, Ränge,
    alter Rang, Admins ausgenommen, Allianzen, zerstörte Planeten, Ids in
    verschiedenen Scheiben), `LargeUniverseTest` (Indizes, `claim`,
    HMAC-Sitzung und Umstellung, nächster freier Platz, volles Universum
    mit allen 22.455 Plätzen, Registrierung meldet es, Admin-Listen,
    Highscore-Seiten), `LargeUniverseMysqlTest` (Neuaufbau einmal, kein
    Neuaufbau nach fremdem Claim, Highscore über Rang 10.000 im Browser,
    alte Sitzung angenommen und umgestellt), Betrugserkennung (öffentliche
    Adresse, Tagesgrenze), Lobby zählt je Universum. 14 Mutationsproben
    schlugen an (zwei erst, nachdem die Tests geschärft waren), eine
    Kontrollprobe (eine einzige Scheibe) bestand wie erwartet.
  - **Empfehlung** für große Universen: `stat_update_time` auf 15 Minuten
    oder mehr; php-fpm (der Neuaufbau läuft dann nach der Antwort); MySQL
    oder MariaDB mit `innodb_buffer_pool_size` in der Größe der Daten
    (1 Mio. Spieler ≈ 2,5 GB); mehrere Universen statt eines übervollen.
- ✅ **Audit auf Code-Injection** (alle Eingabefelder und Schnittstellen).
  - *SQL-Injection: keine gefunden.* Alle rohen Abfragen per Parser
    (`nikic/php-parser`) gesucht, auch über die Hilfsmethoden `rows()`,
    `fetchRows()`, `selectRow()` u. a. (110 Stellen mit Variablen). Jede
    ist gebunden, eine Zahl, eine feste Liste (Spalten, Sortierung,
    Ressourcen) oder intern (Präfix, Konstanten); Datenbanknamen von
    Universen werden gegen ein Muster geprüft.
  - *PHP-Code und Objekte:* `unserialize` nur ohne Klassen; die
    Flottenliste von Fleet3/4 kommt aus der Sitzung. Der Sprach-Editor
    schreibt Werte korrekt maskiert, Pfade nur unter `lang/`. Kein `eval`,
    keine Shell, Downloads nur geprüfte Dateinamen, Weiterleitung nur zu
    Stripe.
  - *XSS, dynamisch geprüft:* In jedes Textfeld, das Spieler, Admins,
    HTTP-Kopfzeilen oder die Blockchain füllen können (328 Stellen: Namen,
    Planeten, Allianz mit Rängen und Texten, Nachrichten, Notizen,
    Freunde, ACS, Kurzwahl, User-Agent, aufgerufene Seite, IP,
    Anmeldungen, Verdachtsfälle, WAX-Memo und -Konto, Sperrgrund,
    Changelog, Spielname) ein Marker geschrieben, der ein Element und ein
    Attribut erzeugt, wenn er ungefiltert ankommt. Dann 268 Seiten als
    Spieler und Admin gecrawlt, jeder GET-Parameter zusätzlich mit dem
    Marker (reflektiert). Gewertet wurde, was der Browser daraus baut,
    und der Text in `onclick`/`onmouseover`, den der Browser vor dem
    Skript entschlüsselt.
  - *Gefunden und behoben:* Spieler-, Planeten- und Allianznamen,
    Allianz-Tag und Rangnamen kamen ungefiltert in Galaxie, Phalanx,
    Übersicht, Highscore, Allianzseiten, Chat, Flottenereignisse und
    Kampfberichte; in den Galaxie-Tooltips in einem Skript-String in einem
    Attribut, wo Blades einfaches Escaping nicht reicht (der Browser
    dekodiert vor dem Skript: ein `'` beendete den String, `<` wurde HTML).
    Jetzt `e()` an jeder Stelle, `Html::scriptText()` für Tooltip-Text,
    `Html::attributeScript()` für die Bestätigung beim Ausschließen.
    Nachrichten sind HTML (Berichte mit Links): Absender und Betreff mit
    Namen werden beim Schreiben escaped (Chat, Freunde, Allianz,
    Rundschreiben, ACS-Einladung, Spionage, alle Missionsberichte).
    Admin-Rundschreiben sind Text in der gewählten Farbe, kein HTML mehr.
    Changelog-Text escaped.
  - *Wer es ausnutzen konnte:* Spieler-Eingaben waren größtenteils schon
    gefiltert (Namen nur Buchstaben und Ziffern, Allianz ohne `<>"'&\`,
    Chat escaped). Admins nicht: Spielername (bis 64 beliebige Zeichen),
    Planeten- und Mondname, Spieler anlegen. Mit mehreren Universen ist
    der Admin eines Universums kein Betreiber: über einen Namen konnte er
    Skript bei Spielern ausführen. Jetzt gelten im Admin dieselben Regeln
    (`Html::UNSAFE_IN_NAMES`), und die Migration
    `2026_10_18_000041_clean_unsafe_names` bereinigt vorhandene Namen.
  - *Sprach-Editor:* Die Dateien in `lang/` gelten für alle Universen und
    `/operator`, einige werden als HTML ausgegeben. Sind Universen
    registriert, ist der Editor geschlossen (403 mit Hinweis, zehn
    Sprachen); der Betreiber bearbeitet `lang/` auf dem Server.
  - *Nebenbei gefunden:* Die Admin-Seite eines gesperrten Spielers endete
    mit Fehler 500 (`$ban->ban_reason`, die Spalte heißt `details`).
  - *Nicht betroffen:* die übrigen Admin-Seiten (Blade escaped; die
    Nachrichtenliste zeigt Nachrichten wie im Spiel, also wie sie
    geschrieben wurden),
    `/operator`, reflektierte Parameter, die Debugbar (JSON-kodiert, nur
    mit `APP_DEBUG=true`).
  - *Tests:* `StoredXssMysqlTest` (Galaxie mit Tooltips, Highscore,
    Allianz mit Ausschluss-Bestätigung, Chat, Übersicht, gesendete
    Nachricht), `HtmlTest` (Escaping für Skript-String und Attribut,
    Namen bereinigen, Migration, Sprach-Editor geschlossen). 14
    Mutationsproben schlugen an (zwei erst, nachdem der Test einen
    inaktiven Spieler und den richtigen Zweig traf); eine Kontrollprobe
    ohne Kolonie im Test bestand wie erwartet.

- **Alpha a03 — Spielzeit per Scheduler (`xgp:tick`)**
  - *Vorher:* Statistik, Aufräumen, Backup, Bots, Umzüge, Auktionen und
    fällige Flotten liefen nur in Seitenaufrufen. Ohne Besucher stand ein
    Universum still; bei vielen Besuchern konnte ein Seitenaufruf die
    Statistik eines ganzen Universums neu berechnen (in `afterResponse`,
    aber im selben PHP-Worker).
  - *Jetzt:* `php artisan xgp:tick` läuft jede Minute per Scheduler
    (`withoutOverlapping(10)`) und arbeitet jedes offene Universum in
    dessen eigenem Kontext ab (Datenbank, Cache, Optionen, Sprache,
    Zeitzone); ohne Universen das eine Spiel. Fehler eines Universums
    werden gemeldet, die übrigen laufen weiter, der Befehl endet dann mit
    Fehlercode.
  - *Herzschlag:* Der Tick schreibt `tick_last_run` in die Optionen des
    Universums. Ist er jünger als 300 Sekunden, verarbeiten Seitenaufrufe
    nur noch, was der Spieler sofort sehen muss (fällige Flotten, Umzüge,
    Auktionen) und lassen Statistik, Aufräumen, Backup und Bots dem
    Scheduler. Fällt der Cron aus, übernehmen die Seitenaufrufe nach fünf
    Minuten wieder alles. Die Sperren (`SettingsService::claim`)
    verhindern doppelte Läufe zwischen Tick und Seitenaufruf.
  - *Nebenbei gefunden:* Der Anteil der Trümmer (`fleet_cdr`,
    `defs_cdr`) war eine PHP-Konstante, die beim ersten Seitenaufruf
    eines Prozesses gesetzt wurde. Mit mehreren Universen in einem Worker
    (oder im Tick) galt der Wert des ersten Universums für alle. Jetzt
    liest `DebrisFactor::share()` ihn aus den Optionen des aktuellen
    Universums.
  - *Tests:* `InstancesMysqlTest` (zwei Universen mit 30 % und 70 %
    Trümmern, Herzschlag je Universum, geschlossenes Universum wird nicht
    berechnet), `LargeUniverseMysqlTest` (Seitenaufrufe überlassen die
    Statistik dem laufenden Scheduler; der Tick berechnet sie und
    schreibt den Herzschlag). Vier Mutationsproben schlugen an
    (Seitenaufrufe ignorieren den Scheduler, kein Herzschlag, Trümmer aus
    der Konstante, nur das erste Universum).

- **Alpha a02 — Sitzungen und Cache auf Redis**
  - *Konfiguration:* `CACHE_DRIVER=redis`, `SESSION_DRIVER=redis`. Neue
    Redis-Verbindung `session` (Datenbank 2, eigener Host möglich über
    `REDIS_SESSION_HOST`), die Sitzungen automatisch nutzen: `cache:clear`
    und Verdrängung im Cache melden niemanden ab. `.env.example` erklärt
    es, die CI startet einen Redis und testet dagegen (`REDIS_TEST_HOST`).
  - *Getrennte Universen:* Mit Sitzungen in der Datenbank waren die
    Universen automatisch getrennt. In Redis teilen sie sich einen
    Speicher, und derselbe Browser schickt in jedem Universum dasselbe
    Sitzungs-Cookie; Spieler 1 in u1 ist in u2 jemand anderes. Die
    Sitzungen liegen jetzt unter dem Präfix des Universums, der Test
    meldet sich in u1 an und kommt mit demselben Cookie in u2 weder ins
    Spiel noch auf `/account/security` (2FA) oder `/account/wax`, die nur
    an Laravels Anmeldung hängen.
  - *Gefunden und behoben:* (1) Der Datei-Cache kennt kein Präfix: Mit
    `CACHE_DRIVER=file` (Standard) teilten sich alle Universen einen
    Cache. Die Lobby zeigte für jedes Universum die Spielerzahl des
    zuerst gezählten, die Zwei-Faktor-Sperre von Spieler 5 galt in allen
    Universen. Jetzt ein Ordner je Universum. (2) Das Cache-Präfix endete
    ohne Trenner (`…_u1` + Schlüssel): der Schlüssel `1x` in u1 war der
    Schlüssel `x` in u11. Jetzt `…_u1:`. (3) Ein Wechsel des Universums
    in einem Prozess (Tests, langlebige Worker) behielt den
    Sitzungsspeicher und das `session.store`, aus dem der Login gelesen
    wird. `InstanceContext` baut sie neu, außer eine Sitzung läuft schon
    (die Lobby schaut in alle Universen und braucht danach ihr CSRF-Token).
  - *`exit` im Spiel:* `Functions::redirect()` beendete den Prozess mit
    `exit`, ausgerechnet in den drei Sitzungsprüfungen von `Users`
    (Spieler-ID, Login, Passwort-Fingerabdruck). Kein Test konnte sie
    erreichen: PHPUnit endete dort mit Status 0, die CI zeigte den Lauf
    als bestanden. Jetzt eine `HttpResponseException` (Laravel speichert
    die Sitzung wie sonst auch). Die Erweiterung `Tests\Support\RunMustFinish`
    lässt einen Lauf scheitern, der vor seinem Ende abbricht, und nennt
    den Test.
  - *Tests:* `InstancesMysqlTest` (Redis: Sitzungen und Cache je
    Universum, auch wenn nur die Sitzungen auf Redis liegen; Dateien:
    Cache je Universum, Spielerzahl der Lobby, CSRF-Token der Lobby),
    `SessionGatesMysqlTest` (passende Sitzung, Sitzung eines anderen
    Spielers, altes Passwort). Neun Mutationsproben schlugen an; die
    Probe „wieder `exit`“ scheitert erst durch `RunMustFinish`.

- **Alpha a01 — Produktionsbetrieb mit einem Befehl (`deploy/`)**
  - *Stack:* Caddy (HTTPS mit Let's Encrypt, HTTP/3, statische Dateien),
    mehrere php-fpm-8.4-Container mit OPcache (ohne Dateiprüfung) und JIT,
    Scheduler-Container (`schedule:work`), MariaDB 11.4, Redis für den
    Cache (`allkeys-lru`) und ein zweiter für die Sitzungen (`noeviction`,
    AOF). PHP läuft als `www-data`; von außen sind nur 80/443 offen.
    Logs rotieren. Statt nginx Caddy: TLS-Erneuerung ohne certbot, und
    Caddy findet die App-Container über das interne DNS und wiederholt
    Anfragen an einen gerade stoppenden Container bei einem anderen.
  - *`deploy/deploy.sh`:*
    - erzeugt einmalig Schlüssel und Passwörter in `deploy/.env` und baut
      das Image;
    - installiert das Spiel beim ersten Mal (`xgp:install`);
    - setzt die Datenbank-Rechte für die Universen und spielt die
      Migrationen ein, während die alte Version weiterläuft;
    - startet neue App-Container neben den alten und entfernt die alten
      erst, wenn alle neuen bereit sind;
    - behält die Images der letzten drei Releases.
    
    Mit `XGP_IMAGE` rollt es ein anderswo gebautes Image aus.
  - *Kein kaputtes Release live:* Jeder App-Container rendert beim Start
    intern die Startseite (php-fpm auf 127.0.0.1:9001) und öffnet Port
    9000 erst danach. Erst schickte Caddy neuen Containern sofort
    Anfragen: Ein Release, dessen Seiten mit 500 enden, traf 598 von 2.250
    Anfragen, bevor der Rollout abbrach. Mit der Selbstprüfung bekommt es
    keine einzige; das Skript bricht nach 9 s ab, die alte Version läuft
    weiter.
  - *`xgp:install`:* die Installation ohne Browser (Container), über
    `GameInstaller`. Sie tut nichts, wenn ein Spiel da ist, fragt dann
    auch nichts, und installiert nur in eine leere Datenbank. Bricht sie
    ab, ist die Datenbank wieder leer; zuerst blieb eine halbe
    Installation zurück, die der nächste Lauf für fertig hielt.
  - *Nebenbei gefunden:*
    - `APP_VERSION` fehlte in der neuen `.env`, und die Installation brach
      im Seeder ab: Die Version gehört zum Code und hat jetzt einen
      Standardwert.
    - `public/compiles` enthielt kompilierte Templates des alten
      BladeOne, öffentlich abrufbar und von nichts mehr benutzt: entfernt.
    - Das Skript löschte beim Aufräumen fremde Image-Tags (auch das
      übergebene `XGP_IMAGE`); jetzt nur die eigenen aus
      `deploy/.releases`.
    - Ein MariaDB-Init-Skript lief nicht (ausführbar statt eingebunden);
      der GRANT steht jetzt im Deploy-Skript und wirkt auch bei
      bestehenden Volumes.
    - Unter Last scheiterten gute Rollouts. `compose ps app` zählte den
      Einmal-Container der Migrationen mit. Außerdem beendete in der
      Renderprüfung `head` die Pipe vor `docker exec`, was `pipefail` für
      eine kaputte Seite hielt. Die Spieler merkten nichts, die alte
      Version lief weiter, aber das Update ging nicht durch. Danach
      gelangen 6 von 6 Rollouts unter Last, mit 96.776 Anfragen ohne Fehler.
  - *Geprüft, live in Docker:*
    - Installation von Null; Crawl aller 53 Spielseiten über HTTPS mit
      Login (Secure-Cookies, Sitzungen in Redis);
    - `.env`, `.htaccess` und `/compiles` liefern 404; Admin-Login führt zur
      Pflicht-2FA;
    - `xgp:tick` jede Minute; ein Universum mit Bots im Container angelegt;
    - 19.244 Anfragen während eines Rollouts ohne einen Fehler;
    - zwei kaputte Releases (php-fpm startet nicht / Seiten mit 500)
      abgewiesen, ohne dass eine Anfrage scheiterte.
    
    Die CI baut den Stack bei jedem Push, installiert, prüft HTTPS und
    rollt ein zweites Mal unter Last aus. Doku: `docs/deployment.md`.
  - *Tests:* `InstallGameMysqlTest` (leere Datenbank mit Admin, zweiter
    Lauf ohne Rückfrage, abgebrochene Installation hinterlässt eine leere
    Datenbank, fremde Tabellen bleiben unberührt, ohne Konfiguration nichts).

- **Alpha a04 — Rechtsseiten, Widerrufsverzicht, Kaufbestätigung**
  - *Rechtsseiten:* Impressum, Datenschutzerklärung, AGB und
    Widerrufsbelehrung unter `/legal/{page}`, ohne Anmeldung und ohne
    Universum. Die Texte schreiben Betreiber unter /operator →
    *Rechtliches*: je Sprache ein Markdown-Text in der Basisdatenbank,
    gleich für alle Universen, jede Änderung im Protokoll. Fehlt die
    Sprache des Besuchers, erscheint der zuerst geschriebene Text. HTML
    und `javascript:`-Links bleiben Text. Verlinkt ist nur, was einen Text
    hat: Fußzeile von Start, Lobby und Spiel, Registrierung, Shop.
  - *Toter Link:* Die Registrierung verwies auf `index.php?page=terms` und
    `?page=policy`, Routen, die es seit dem Umbau nicht mehr gab.
  - *Widerrufsverzicht:* Dunkle Materie ist ein digitaler Inhalt. Der Shop
    hat jetzt ein Formular mit Paketwahl und einem Pflicht-Haken: „sofort
    gutschreiben, Widerrufsrecht erlischt“. Der Server prüft den Haken bei
    jedem Kauf, mit jeder Zahlungsart, und speichert den Zeitpunkt an der
    Bestellung (`withdrawal_waived_at`).
  - *Kaufbestätigung per Mail:* nach jeder Gutschrift (Stripe und WAX/Token)
    einmal, in der Sprache des Universums, mit Bestellnummer, Menge,
    Preis, Zahlungsart und der Erklärung zum Widerrufsrecht. Das ist die
    Bestätigung auf dauerhaftem Datenträger. Ein ausgefallener Mailserver
    nimmt keine Gutschrift zurück.
  - *Tests:* `LegalPagesTest` deckt ab:
    - ohne Text 404 und kein Link;
    - Betreiber schreibt, alle lesen;
    - Sprach-Rückfall auch per `Accept-Language`;
    - HTML und Skript-Links bleiben Text;
    - Vorschau speichert nicht, leer entfernt;
    - ohne Anmeldung kein Zugriff, Längenlimit.

    Dazu kommen der Shop ohne Haken (keine Bestellung, kein Aufruf bei
    Stripe) und im Webhook genau eine Bestätigung trotz doppelt
    gesendetem Ereignis. Die Mail rendert mit Verzichtserklärung, und ein
    Mailfehler macht die Zahlung nicht rückgängig. Der
    Sprach-Rückfall ist zusätzlich auf MariaDB geprüft.

- **Alpha a05 — DSGVO: Datenauskunft und Konto sofort löschen**
  - *Deine Daten* (`/account/privacy`, verlinkt in den Einstellungen):
    - Download aller Daten des Kontos als JSON: Konto, Planeten mit allem
      darauf, Forschung, Flotten, Nachrichten, Freunde, Notizen, Allianz,
      Käufe, Gegenstände, Gebote;
    - dazu die Anmeldungen mit IP und Browser, Sitzungen, Aktivität,
      WAX-Verknüpfung und Saisonplätze;
    - nicht enthalten: Passwort, Zwei-Faktor-Schlüssel, Codes und die
      Funde der Betrugserkennung;
    - Sofort-Löschen des Kontos.

    Beides nur mit Passwort (und Code), fünf Fehlversuche sperren 15
    Minuten. Admins können sich nicht selbst löschen.
  - *Gründliches Löschen für alle Wege:* Das alte Löschen (Admin,
    Löschmodus, inaktive Konten) ließ Anmeldespuren mit IP-Adressen,
    Sitzungen, Aktivität, Spionage-Protokoll, Verdachtsfälle,
    Angriffsverbände, Passwort-Tokens und die WAX-Verknüpfung stehen. Jetzt
    gehen alle Wege über `AccountEraser`. Bezahlte Bestellungen bleiben
    (Aufbewahrungspflicht), die Ruhmeshalle behält den Platz ohne Namen.
  - *Nebenbei:* Die Beispiel-`.env` schrieb mit `LOG_CHANNEL=stack` in eine
    einzige Datei ohne Rotation. Jetzt `daily` (14 Tage) und
    `LOG_LEVEL=warning`. Die Fristen für die Datenschutzerklärung stehen in
    `docs/universes.md`.
  - *Tests:* `PrivacyMysqlTest` (gegen MariaDB) prüft:
    - Download mit allen Teilen, ohne Geheimnisse;
    - kein Download ohne Passwort;
    - Löschen entfernt alle Spuren, Bestellung und Ruhmeshallen-Platz
      bleiben, der andere Spieler behält alles;
    - nichts wird gelöscht ohne Passwort, Bestätigung oder Code;
    - ein Admin kann sich nicht selbst löschen.

    Gegenprobe: Ohne das Löschen der Anmeldungen schlägt der Test fehl.

- **Alpha a06 — E-Mail-Adresse bei der Registrierung bestätigen**
  - *Ablauf:* Nach der Registrierung geht ein signierter Link raus (7 Tage,
    nur für diese Adresse, ohne Anmeldung nutzbar, Universum in der
    Signatur), statt der Willkommens-E-Mail. Bis zur Bestätigung erinnert
    jede Spielseite daran, mit Löschdatum und „erneut senden“ (3 pro
    Stunde). Der Shop verkauft nichts. Nach 7 Tagen löscht das Aufräumen
    das Konto über `AccountEraser`.
  - *Als bestätigt gelten:*
    - Admins, Bots, vom Admin angelegte Spieler und vom Admin geänderte
      Adressen;
    - ein abgeschlossenes Passwort-Zurücksetzen;
    - alle bestehenden Konten (Migration setzt `email_verified_at`).

    Ohne die Option `reg_verify_email` (Admin → Registrierung) gilt jede
    neue Adresse sofort als bestätigt. Ein späteres Einschalten löscht
    deshalb niemanden. `email_verified_at` ist nicht mass-assignable:
    Keine Anfrage bestätigt sich selbst.
  - *Gefunden:* Ein frisch von `RegistrationService` zurückgegebenes Modell
    hat noch keinen `authlevel` (die Datenbank setzt ihn). Ein Vergleich
    `authlevel === 0` hätte solche Spieler als „Admin“ durchgelassen. Jetzt
    gilt „kein Rang über 0“; die Tests nehmen genau dieses Modell.
  - *Aufgeräumt:* Die Regeln vor einem Kauf (bestätigte Adresse,
    Widerrufsverzicht) stehen in `PurchaseTerms`, die erste Mail an neue
    Spieler in `EmailVerification::greet()`.
  - *Tests:* `EmailVerificationMysqlTest` deckt ab:
    - Registrierung, Mail, Hinweis, Link ohne Anmeldung, Hinweis weg;
    - gefälschter Link, fremde ID, geänderte Adresse;
    - Shop gesperrt;
    - erneut senden;
    - Löschen nur unbestätigter Spieler (nicht frisch, bestätigt, Admin,
      Bot);
    - ohne Option bestätigt, späteres Einschalten löscht niemanden;
    - Passwort-Reset bestätigt;
    - Link und Mail.

    Gegenproben: Ohne Adressprüfung im Link und ohne Bot-Ausnahme schlagen
    die Tests fehl. Im Browser durchgespielt: Registrierung, Hinweis,
    erneut senden, Link aus der Mail, Hinweis verschwindet.

- **Alpha a07 — Einladungen, Spielerlimit, Testzahlungen**
  - *Einladungen:* Option `reg_invite_only` je Universum und Tabelle
    `invitations`:
    - Code mit Anzahl Nutzungen, Ablauf und Notiz;
    - unter Admin → Registrierung erzeugen, auflisten (mit Link) und
      löschen;
    - der Link `?invite=` füllt das Feld aus;
    - eine Nutzung ist ein einziges `UPDATE … WHERE used < uses`, nie zwei
      für die letzte; scheitert die Registrierung danach, zählt sie
      wieder.
  - *Spielerlimit:* `reg_max_players` (Bots zählen nicht). Startseite und
    Lobby sagen „voll“, die Lobby auch „nur mit Einladung“.
  - *Testzahlungen:* `XGP_PAYMENTS_TEST` lässt nur Stripe-Testschlüssel und
    das WAX-Testnetz zu (`XGP_WAX_NETWORK=testnet`, eigener
    History-Server). Shop, Lobby, Admin und Kaufbestätigung sagen dann,
    dass kein echtes Geld fließt; das gilt auch ohne die Variable, wenn
    alle Zahlungsarten Testarten sind. Die Regeln stehen in `PaymentMode`.
    Der Docker-Stack startet mit Testzahlungen.
  - *Chain-Prüfung (immer aktiv):* Die WAX-Prüfung vergleicht die `chain_id` des
    History-Servers mit `XGP_WAX_NETWORK`; die IDs habe ich live von
    Main- und Testnetz-Servern abgeglichen. Bisher hätte ein falsch
    eingestellter Server Testnetz-Überweisungen als echte Zahlungen
    gutgeschrieben.
  - *Nebenbei:* Nach einer abgelehnten Registrierung waren Name und E-Mail
    leer, die Startseite gab die Eingaben nie zurück. Jetzt bleiben sie.
  - *Tests:*
    - `RegistrationGateMysqlTest`: nur gültiger Code, Schreibweise egal,
      verbraucht, abgelaufen, Link füllt aus, voll mit Bots, Rückgabe nach
      Fehlschlag, Wettlauf um die letzte Nutzung;
    - `InvitationsTest` (Admin);
    - `TestPaymentsTest`: Testmodus, Live-Schlüssel, falsche Chain,
      Testnetz-Standardwerte;
    - Lobby-Kennzeichen in `InstancesMysqlTest`, Testhinweis im
      Shop-Test.

    Im Browser geprüft: Feld, Link, Fehlermeldung am Feld.

- **Alpha a08 — Fehler melden im Spiel**
  - *Melden:* Der Link *Fehler melden* unter jeder Spielseite führt zu
    `/account/report?from=<Seite>`. Gespeichert werden Beschreibung (10
    bis 5000 Zeichen), Seite, Universum, Spieler und Browser in der
    Basisdatenbank (`bug_reports`); höchstens 5 pro Stunde.
  - *Seitenangabe:* Nur ein Pfad dieser Seite zählt. Mit fremdem Host,
    Schema oder `//` bleibt sie leer. Der erste Entwurf kürzte
    `https://evil.example/phish` auf `/phish`, der Test fand das.
  - *Betreiber:* /operator → *Meldungen* listet die neuesten zuerst, nach
    Status gefiltert (neu, gesehen, behoben, alle), 50 je Seite. Der Status
    wird direkt geändert.
  - *Datenschutz:* Meldungen gehören zur Datenauskunft. Beim Löschen eines
    Kontos bleiben sie ohne Namen, ID und Browser.
  - *Tests:* `BugReportsTest` (melden, Seitenangabe, zu kurz/Gast,
    Betreiber listet und ändert, nur Betreiber), Link auf Spielseiten in
    `EmailVerificationMysqlTest`, Export und Anonymisierung in
    `PrivacyMysqlTest`. Im Browser durchgespielt.

- **Alpha a09 — Fehlerüberwachung unter /operator**
  - *Sammeln:* Der Exception-Handler gibt jede gemeldete Ausnahme an
    `ErrorMonitor`. Der fasst sie je Art in der Basisdatenbank zusammen
    (`error_groups`): gleiche Klasse, Datei, Zeile und Meldung ohne Zahlen
    und Werte in Anführungszeichen. Gespeichert werden Anzahl, erstes und
    letztes Auftreten, Universum, Seite (Methode und Pfad, nie die Query)
    oder Befehl und der Stacktrace des ersten Mals. Das Aufzeichnen wirft
    nie, auch ohne Tabelle oder bei ausgefallener Datenbank.
  - *Betreiber:* /operator → *Fehler* zeigt die Gruppen nach Status (offen,
    stumm, erledigt), mit Details und Stacktrace. Eine erledigte Gruppe
    öffnet sich wieder, wenn der Fehler wiederkommt.
  - *Mail:* `xgp:errors:notify` läuft alle 5 Minuten. Er schickt eine
    Zusammenfassung an `XGP_ERRORS_MAIL`, sobald eine offene Gruppe seit
    der letzten Mail die Schwelle erreicht (`XGP_ERRORS_THRESHOLD`,
    Standard 5), je Gruppe höchstens stündlich. Erledigte Gruppen, die 90
    Tage nicht wiederkamen, löscht er.
  - *Nebenbei (eigener Fehler):* Mit `LOG_CHANNEL=daily` aus a05 fand die
    Fehlerliste im Admin die Logdatei nicht mehr und blieb leer. Behoben
    in 086dacf, mit Test.
  - *Tests:* `ErrorMonitorTest` deckt ab:
    - eine Gruppe je Art;
    - Wiederöffnen;
    - nie werfen;
    - Fehler auf einer echten Seite mit Pfad ohne Query;
    - Betreiber sieht und erledigt;
    - Mail erst ab Schwelle, stündlich, Link auf APP_URL;
    - Löschen nach 90 Tagen.

    Hochzählen und Wiederöffnen zusätzlich direkt auf MariaDB geprüft.

- **Alpha a10 — Backups auf einem zweiten Server, Wiederherstellung geprobt**
  - *Sichern:* `xgp:backup:run`, nachts per Scheduler. Er sichert die
    Basisdatenbank und jedes Universum mit `mariadb-dump` direkt in ein
    restic-Repository (S3, B2, SFTP …): verschlüsselt, dedupliziert, ein
    Snapshot je Datenbank (`db:<name>`). Aufbewahrt werden 7 tägliche, 5
    wöchentliche und 12 monatliche Stände. Werte gehen per Umgebung an die
    Shell, `pipefail` lässt einen abgebrochenen Dump den Lauf scheitern.
    Läufe stehen in `backup_runs` und unter /operator → *Backups*, ein
    Fehlschlag geht sofort per Mail raus. restic ist im Docker-Image.
  - *Wiederherstellen:* `xgp:backup:restore <db> --into=<neu>` nur in eine
    neue Datenbank, dazu `xgp:backup:list`. `xgp:instance:update
    --database=` stellt ein Universum um, nur auf eine Datenbank mit Spiel,
    die keinem anderen gehört.
  - *Probe auf MariaDB:*
    1. Universum mit 6 Spielern, Sicherung (2 Snapshots, 1,1 MB, kein
       Klartext im Repository);
    2. alle Planeten gelöscht, Namen verändert;
    3. Wiederherstellung: alle 44 Tabellen prüfsummengleich, Überschreiben
       verweigert;
    4. Universum umgestellt, Tick läuft, Planeten zurück.
  - *Dabei gefunden:*
    - `SHOW DATABASES LIKE ?` nimmt in MariaDB keinen Platzhalter.
    - Der Tabellenpräfix landete vor `information_schema`-Tabellen.
    - Ein falsches Passwort meldete „config file already exists“, weil der
      Lauf dann ein neues Repository anlegen wollte. Jetzt wird nur bei
      fehlendem Repository angelegt, sonst steht der echte Grund da.
  - *Tests:* `OffsiteBackupMysqlTest` (Sichern, Beschädigen, Wiederherstellen
    mit Prüfsummen, kein Überschreiben, falsches Passwort aufgezeichnet).
    Die CI installiert dafür restic und den MariaDB-Client.

- **Alpha a11 — Seiten-Crawl und XSS-Test in der CI**
  - *Ablauf:* Der CI-Job *Browser* führt `tests/Browser/run.sh` aus, lokal
    genauso aufrufbar.
    1. Das Skript installiert das Spiel in eine leere MariaDB.
    2. `seed.php` legt einen zweiten Spieler, eine Allianz, eine Nachricht,
       eine Freundschaftsanfrage, eine Notiz, einen Changelog-Eintrag, einen
       Verdachtsfall, ein Impressum, eine Einladung, eine Fehlermeldung, eine
       Fehlergruppe und einen Betreiber mit bekanntem 2FA-Schlüssel an. Jeder
       Name und Text ist Code, der beim Ausführen `window.__xss` setzt.
    3. `crawl.mjs` öffnet in Chromium (Playwright) 102 Seiten: Spiel, Konto,
       Rechtsseiten, Admin und /operator, in Englisch und Deutsch. Dabei
       löst er jedes `onmouseover` aus (Tooltips bauen ihr HTML erst dann).
  - *Er schlägt fehl bei:*
    - JavaScript-Fehlern, HTTP-Fehlern und fehlgeschlagenen Anfragen;
    - rohen Sprachschlüsseln, übrigen Platzhaltern und Exceptions im Text;
    - jedem Marker, der lief oder als Element in der Seite steht.

    Screenshots der betroffenen Seiten und die Logs werden als Artifact
    hochgeladen.
  - *Dabei gefunden:* Planetennamen standen in einigen Flottenmeldungen
    ungeschützt im HTML: Stationieren, Rückkehr eines Transports ohne
    Ladung, Bauschleife ohne Rohstoffe. Ebenso der Spielername in der
    AKS-Einladung. Namen sind beim Speichern schon auf sichere Zeichen
    beschränkt (`Html::UNSAFE_IN_NAMES`, Umbenennen nur `A-Z0-9_- `). Jetzt
    werden sie auch in diesen Meldungen escaped, wie in allen anderen.

- **Alpha a12 — Lasttest-Werkzeug** (statt eines Skripts in `tools/` als
  Befehle, damit es ohne weitere Installation im Container läuft;
  Anleitung in [docs/deployment.md](docs/deployment.md), „Lasttest vor dem
  Start“)
  - `xgp:loadtest:seed`: Testspieler über die normale Registrierung, dann in
    Blöcken zu 500 ausgebaut (`LoadTestWorld`). Nur in einem Universum ohne
    echte Spieler, das danach nur noch Einladungen annimmt. Der Hash pro
    Spieler wird mit niedrigen Kosten erzeugt und am Ende durch einen echten
    ersetzt (1.200 Spieler in unter einer Minute).
  - `xgp:loadtest:run`: Anmeldung wie im Browser (Token, Universum in der
    Adresse, wartet die Anmeldesperre ab), dann parallele Seitenaufrufe mit
    Guzzle. Ausgabe: Seiten pro Sekunde, Antwortcodes, Median, 95 % und
    99 %.
  - `xgp:loadtest:clear`: löscht die Testspieler über den `AccountEraser`.
  - *Dabei gefunden, Konto löschen mit Allianz:* `Users::deleteUser`
    behandelte jedes Mitglied wie den Gründer, ein Fehler aus dem Original:
    - Hatte die Allianz keinen Rang „rechte Hand“, wurde sie für alle
      aufgelöst.
    - Hatte sie einen, bekam dieser Rang die Allianz, auch wenn nur ein
      einfaches Mitglied ging.
    - Auf MariaDB scheiterte das Auflösen zusätzlich an
      `ally_register_time = ''` im strikten Modus. Die Löschung lief
      deshalb gar nicht durch: weder „Konto löschen“ noch das Aufräumen
      inaktiver Konten noch das Löschen durch den Admin.

    Jetzt geht ein Mitglied einfach. Der Gründer übergibt an die rechte
    Hand, sonst an das älteste Mitglied. Das letzte Mitglied nimmt die
    Allianz mit, offene Bewerbungen werden frei.
  - *Tests:* `LoadTestPlayersMysqlTest` (Ausbau, Passwort, Weitermachen,
    Weigerung bei echten Spielern, nichts bleibt zurück), `LoadRunnerTest`
    (Anmeldung mit Token, Warten auf die Sperre, falsches Passwort, Zählen
    der Antworten), `AllianceOnDeleteMysqlTest` (drei der vier Fälle
    scheitern mit dem alten Code).

- **Alpha a13 — Die wichtigsten Seiten auf dem Handy**
  - *Vorher*, geprüft bei 390 px (iPhone-Größe) in Chromium mit
    Touch-Emulation, nicht auf echten Geräten: Kein Layout hatte ein
    Viewport-Tag. Das Handy zeigte jede Seite als 980 px breiten Desktop,
    auf 40 % verkleinert. Schrift etwa 4 pt, Links nicht zu treffen.
  - *Jetzt* gibt es Viewport-Tags und zwei Stylesheets, die nur unter
    760 px greifen (`assets/css/mobile.css`, `assets/css/home/mobile.css`).
    Der Desktop bleibt unverändert.
    - Das Menü klappt hinter einen Knopf „☰ Menü“ (zehn Sprachen) und
      öffnet als Raster mit 44 px hohen Feldern.
    - Kopf: Planetenwahl, Rohstoffe und Offiziere stehen untereinander.
    - Gebäude, Forschung, Werft und Verteidigung: Bild (72 px) und Text
      nebeneinander, der Knopf darunter über die volle Breite.
    - Flotte: alle drei Schritte passen. In Schritt 3 stehen Auftrag und
      Rohstoffe untereinander.
    - Die Galaxie behält ihre Spalten und scrollt in sich seitwärts, die
      Seite selbst nicht. Tooltips öffnen auf Antippen und bleiben auf dem
      Bildschirm.
    - Startseite (Anmelden, Registrieren) und „Passwort vergessen“ stehen
      untereinander statt 718 px nebeneinander.
  - *Tests:* Der Browser-Crawl der CI öffnet zusätzlich Startseite,
    Passwortseite und zwölf Spielseiten bei 390 px. Er scheitert, sobald
    eine davon breiter ist als der Bildschirm oder das Menü nicht aufgeht.
    Gegenprobe ohne Viewport-Tag: 13 Treffer.
  - *Offen:* Nicht geprüft auf echten Geräten (iOS Safari, Android
    Chrome).
  - *Nachtrag, breite Listen:*
    - Bewegungen, Allianzmitglieder und Freunde werden auf dem Handy zu
      Karten: eine Zeile pro Wert, davor der Spaltenname (`data-label`).
      Die Sortierlinks der Mitgliederliste bleiben als Leiste.
    - Imperium behält seine Planeten-Spalten und scrollt seitwärts. Die
      Zeilennamen bleiben dabei links stehen.
    - Übersicht: Mond und Planet oben, die Kolonien darunter. Mit
      Kolonien war sie vorher 518 px breit.
    - Ressourceneinstellungen sind enger gesetzt. Lange Wörter in
      Nachrichten brechen um.
    - *Dabei gefunden:* Der Text von Freundschaftsanfragen wurde doppelt
      escaped, „I'm“ erschien als „I&#039;m“.
    - *Test:* Der Phone-Teil des Crawls prüft jetzt 19 Seiten. Er misst
      auch die Breite des Inhalts, nicht nur die der Seite. Bisher fiel eine
      zu breite Tabelle nicht auf, weil sie in sich scrollte. Galaxie und
      Imperium dürfen absichtlich scrollen.


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
- ✅ **Schiffe von OGame 7** (Zielversion: das Spiel mit dem
  Klassen-Update; Werte aus dem OGame-Wiki):
  - **Schlachtkreuzer** wieder unter seiner Id 215 (30.000/40.000/15.000,
    Schild 400, Angriff 700, Tempo 10.000, Laderaum 750, Schnellfeuer gegen
    KT/GT 3, SJ/Kreuzer 4, SS 7). Unter 215 stand ein Zwitter: Name, Preis
    und Kampfwerte des Reapers, Anforderungen und Schnellfeuer (Zerstörer
    2, Todesstern) des Schlachtkreuzers.
  - **Reaper** unter seiner Id 218 mit den Werten des Originals (Verbrauch
    1.100 statt 550; Anforderungen Werft 10, Hyperraumtechnik 6,
    Hyperraumantrieb 7, Schildtechnik 6). Bestehende Reaper bleiben Reaper
    (Spalte `ship_reaper`); Flotten im Flug und Werftaufträge mit 215
    werden per Update-Migration auf 218 umgeschrieben.
  - **Pathfinder** (219) und **Crawler** (217) neu; der Crawler fliegt
    nicht.
  - Schnellfeuer gegen die neuen Schiffe (alle gegen Crawler 5, Todesstern
    1.250/15/10/30, Schlachtschiff gegen Pathfinder 5, Ionenkanone gegen
    Reaper 2).
  - Die Klassen-Beschränkungen (Reaper nur General usw.), die Wirkung der
    Crawler und die Expeditionsboni des Pathfinders folgen mit den
    Klassen.
  - Bilder für Reaper, Pathfinder und Crawler sind eingefärbte Varianten
    vorhandener Grafiken (Platzhalter).
  - Zwei Kampf-Referenzwerte (BattleOutcomeTest, „several players“) sind
    neu erfasst, weil das Schnellfeuer des Zerstörers jetzt dem
    Schlachtkreuzer gilt. Tests für die neuen Werte und die
    Datenmigration auf MariaDB.
- ✅ **Spielerklassen** (OGame 7; `App\Enums\PlayerClass`,
  `PlayerClassService`, Seite `characterclass` im Menü „Klasse“): Die
  erste Wahl ist kostenlos, ein Wechsel kostet 500.000 Dunkle Materie
  (Einstellung `class_change_price`, wie das Wechsel-Token des Originals).
  - **Kollektor**: baut Crawler; +25 % Minenproduktion, +10 % Energie;
    Transporter +100 % Tempo und +25 % Laderaum; Crawler-Bonus ×1,5, mit
    Geologe 10 % mehr aktive Crawler, Überladen bis 150 %.
  - **General**: baut Reaper; Kampfschiffe (außer Todesstern) und Recycler
    +100 % Tempo; −25 % Deuteriumverbrauch; Recycler und Pathfinder +20 %
    Laderaum; +2 Stufen Waffen/Schild/Panzerung im Kampf; +2 Flottenslots;
    +5 Felder auf jedem Mond (beim Wechsel angepasst).
  - **Entdecker**: baut Pathfinder; −25 % Forschungszeit; Rohstoff- und
    Schiffsfunde auf Expeditionen ×1,5; halb so viele Piraten/Aliens; +2
    Expeditionsslots; neue Kolonien 10 % größer (21 % mehr Felder); +20 %
    Phalanx-Reichweite; 75 % Beute bei Spielern, die eine Woche nicht
    online waren.
  - **Crawler** (`ClassProductionService`): je arbeitendem Crawler +0,02 %
    der Minenproduktion (Kollektor 0,03 %), höchstens 8 je Minenstufe,
    zusammen höchstens +50 %, je 50 Energie (bei 150 % doppelt); eigene
    Zeile und Einstellung in den Rohstoffeinstellungen, nur auf Planeten.
  - Die Bonusbasis der Booster und Klassen ist jetzt nur die
    Minenproduktion; der Deuteriumverbrauch des Fusionskraftwerks zählte
    bisher mit hinein.
  - **Nicht übernommen**: Rabatte auf das Fertigstellen mit Dunkler Materie
    (das gibt es im Spiel nicht), 5-%-Tempostufen und die Chance, mit einem
    Leichten Jäger einen Todesstern zu zerstören (General), sichtbare
    Expeditions-Trümmerfelder (Entdecker; kommt mit den Trümmerfeldern an
    Position 16).
  - Tests für alle Boni, Wahl und Wechsel; im Browser durchgespielt.
- ✅ **Allianzklassen** (OGame 7; `App\Enums\AllianceClass`,
  `AllianceClassService`, Allianzverwaltung → „Allianzklasse“): Wer die
  Allianz verwalten darf, wählt die Klasse für alle Mitglieder; die erste
  Wahl ist kostenlos, ein Wechsel kostet ihn `class_change_price` Dunkle
  Materie. Die Klasse steht auf der Allianzseite. Spieler- und
  Allianzklasse laufen jetzt gemeinsam durch `App\Support\ClassBonuses`
  (Flotten-Formeln, Produktion, Kampf, Spionage, Kolonisieren).
  - **Händler**: Transporter +10 % Tempo; +5 % Minen- und
    Energieproduktion; +10 % Lagerkapazität (Rohstoff-Kappung, Anzeigen,
    Händler, Schrotthändler, Rohstoffmarkt, Technik-Tabelle).
  - **Forscher**: neue Kolonien 5 % größer (mit dem Entdecker
    multipliziert); Expeditionen fliegen 10 % schneller.
  - **Krieger**: +1 Stufe Waffen/Schild/Panzerung im Kampf (mit dem
    General +3); +1 Stufe Spionagetechnik (beim Spionieren und beim
    Abwehren); Flüge zu Planeten von Allianzmitgliedern 10 % schneller.
  - Der Tempobonus für Expeditionen und Allianzflüge wirkt auf die
    Flugzeit beim Absenden; die Zeitvorschau in Flotte 2 zeigt ihn mit
    einem Hinweis, wie im Original (der Treibstoff bleibt gleich).
  - Die erste Wahl ist erst kostenlos, wenn die Allianz 14 Tage besteht;
    vorher kostet auch sie den Preis des Wechsel-Tokens. Die Seite nennt
    das Datum.
  - **System-Phalanx** (Forscher, Changelog 8.0): Button in der Galaxie,
    wenn der aktuelle Mond eine Phalanx hat und das System in Reichweite
    liegt. Ein Scan kostet 40.000 Deuterium (`SYSTEM_PHALANX_COST`) und
    zeigt die Flottenbewegungen aller fremden Planeten des Systems.
  - **System-Spionage** (Krieger, Changelog 8.0): Formular in der Galaxie;
    schickt Sonden an alle Ziele des Systems, jede Mission mit eigenen
    Sonden, Deuterium und Flottenslot, bis eines davon ausgeht. Optionen:
    Sonden je Ziel, Planeten/Monde/beides, aktive/inaktive/beide, Ziele
    überspringen, die in den letzten N Stunden ausspioniert wurden
    (Tabelle `espionage_log`, auch für einzelne Spionage aus Galaxie und
    Flottenmenü geführt). Noob-Schutz, Urlaubsmodus und geschützte Admins
    gelten wie bei der einzelnen Spionage. Die Optionen bleiben in der
    Sitzung.
  - Tests für die Boni, Wahl und Wechsel sowie die Verwaltungsseite; im
    Browser durchgespielt.
- ✅ **Raumdock und Wrackfelder** (OGame 7; owiki „Raumdock“;
  `WreckFieldService`, Seite `spacedock`, Tabelle `wreck_fields`):
  - **Raumdock** (Gebäude 36, Anlagen, nur Planeten): Voraussetzung
    Raumschiffwerft 2; Stufe 1 kostet 200 Metall, 50 Deuterium und 50
    Energie, Metall und Deuterium ×5 je Stufe, Energie ×2,5 (Tabelle des
    Originals; `Price` hat dafür einen eigenen Energie-Faktor). Es schwebt
    im Orbit und belegt kein Feld.
  - **Wrackfeld**: entsteht nach einem Kampf über einem Planeten oder
    seinem Mond, wenn mindestens 150.000 Einheiten und 5 % der Schiffe des
    Verteidigers dort zerstört wurden. Darin liegt, was nicht zu Trümmern
    wurde (bei 30 % Trümmerfeld 70 %); Solarsatelliten und Crawler
    hinterlassen keine Wracks. Es liegt immer beim Planeten (dort steht
    das Raumdock). Ein weiterer Kampf legt Wracks dazu und gibt wieder 3
    Tage; ohne begonnene Reparatur verglüht es nach 3 Tagen.
  - **General als Angreifer**: seine zerstörten Schiffe bilden nach
    denselben Regeln ein Wrackfeld an seinem Startplaneten, reparierbar,
    sobald die Flotte zurück wäre.
  - **Reparatur**: Das Raumdock repariert 45 % der Wracks auf Stufe 1 bis
    56 % ab Stufe 14 (31,5 % bis 39,2 % der zerstörten Schiffe wie in der
    Tabelle des Originals), kostenlos, in der Bauzeit der Werft, mindestens
    30 Minuten, höchstens 12 Stunden. Danach stellt der Spieler die
    Schiffe in Dienst, spätestens nach 3 Tagen geschieht es von selbst
    (beim nächsten Aktualisieren des Planeten).
  - Hinweis auf der Übersicht und Link beim Raumdock in den Anlagen.
  - **Galaxieansicht**: Ein Wrackfeld erscheint in der Spalte „TF“ mit
    seinen Schiffen und der Zeit bis zum Verglühen (oder „In Reparatur“),
    für den Besitzer mit Link zum Raumdock des Planeten.
  - **Vorzeitig in Dienst stellen**: Nach 30 Minuten Reparatur lassen sich
    die bis dahin reparierten Schiffe in Dienst stellen (jeder Typ im
    gleichen Tempo, abgerundet); die übrigen Wracks gehen verloren. Die
    Seite zeigt, wie viele schon repariert sind.
  - **Kämpfe während einer Reparatur**: Der Start der Reparatur verbraucht
    die Wracks (was das Dock nicht repariert, ist verloren). Neue Wracks
    sammeln sich daneben mit eigenen 3 Tagen; das Raumdock zeigt sie unter
    „Neue Wracks“, die Galaxie zeigt sie statt der Schiffe in Reparatur.
    Nach dem Indienststellen sind sie das nächste Wrackfeld und lassen sich
    reparieren (pro Planet eine Reparatur zur Zeit, wie im Original).
  - Rundung: Die Kosten werden wie bei allen Gebäuden gerundet, die
    Energie für Stufe 3 ist also 313 statt 312.
  - Tests für Kosten, Schwellen, Inhalt, Reparatur, Indienststellung,
    Verglühen, Angreifer-Wracks und das Feld; ein echter Angriff auf
    MariaDB; im Browser gebaut, repariert und in Dienst gestellt.
- ✅ **Pathfinder, Reaper und Trümmerfelder im tiefen Raum** (OGame 7):
  - **Pathfinder auf Expeditionen**: Ist mindestens einer in der Flotte,
    verdoppeln sich Rohstoff- und Schiffsfunde (mit dem Entdecker-Bonus
    multipliziert; der Laderaum begrenzt weiter).
  - **Trümmerfeld an Position 16** (`DeepSpaceDebrisService`, Tabelle
    `deep_space_debris`): Kämpfe gegen Piraten und Aliens hinterlassen
    10 % von Metall und Kristall aller zerstörten Schiffe, beim Entdecker
    20 % (Changelog 7.1). Nur Pathfinder bauen es ab (Recycler fliegen
    nicht in den tiefen Raum); nur Entdecker sehen es in der Galaxie, mit
    Link zum Abbauen. Flotte 3 bietet dort nur „Abbauen“ an, und nur mit
    Pathfindern.
  - **Pathfinder bauen auch normale Trümmerfelder ab**, zusammen mit den
    Recyclern.
  - **Reaper** sammeln nach einem Kampf gegen Spieler bis zu 25 % des
    neuen Trümmerfelds direkt ein, so weit ihr Laderaum (und der nach der
    Beute freie Raum der Flotte) reicht; der Kampfbericht nennt die
    Menge. Gegen Piraten und Aliens nicht.
  - `Recycle::splitRecycledDebris()` ist jetzt `DebrisLoad::split()` (auch
    für die Reaper); eine negative Kapazität lädt nichts mehr aus.
  - Annahmen: Die Größe des Expeditions-Trümmerfelds (10/20 %) folgt dem
    Changelog 7.1; wie das Original es genau berechnet, ist nicht
    dokumentiert.
  - Tests für die Funde, das Feld, den Abbau (auch auf MariaDB), die
    Expeditionskämpfe und die Reaper in einem echten Kampf; im Browser vom
    Galaxie-Link bis zur Ladung durchgespielt.
- 🔴→✅ **Admin-Flottenliste stürzte ab** (gefixt): Die Namen der
  Admin-Listen kannten Crawler, Reaper (218) und Pathfinder nicht, unter
  215 stand noch „Reaper“ statt Schlachtkreuzer. Sobald ein Pathfinder
  flog, endete `/admin/fleets` mit „Undefined array key 219“. Ein Test
  prüft jetzt, dass jedes Objekt in allen drei Sprachen einen Namen hat.
- 🔴→✅ **Admin-Reset der Bau- und Forschungsschleifen wirkungslos**
  (gefixt): Er leerte nur die Cache-Spalten der Planeten, die Aufträge in
  `building_queues`/`research_queues` liefen weiter und wurden fertig
  gebaut. Auch der Komplett-Reset ließ sie (und jetzt Gegenstände und
  Booster) stehen, weil dort die Fremdschlüssel abgeschaltet sind. Tests mit
  Gegenprobe; der Komplett-Reset hat jetzt einen MariaDB-Test.
- 🔴→✅ **Offiziere wirkten nicht wie beschrieben** (gefixt, owiki
  „Kommandostab“; `App\Support\OfficerBonuses`):
  - **Admiral**: +1 Expeditionsslot fehlte (nur die Flottenslots wirkten).
    Neu: die **Beutepriorität** – bei Angriffen wählt man in Flotte 3 die
    Reihenfolge der Rohstoffe (z. B. Deuterium > Kristall > Metall), jeder
    bis zur Hälfte des Vorrats (`fleets.fleet_loot_priority`).
  - **Ingenieur**: „halbiert die Verluste der Verteidigung“ wirkte nicht;
    jetzt bleiben von zerstörter Verteidigung 15 % statt 30 % verloren
    (85 % statt 70 % werden wieder aufgebaut).
  - **Kommandostab** (alle fünf Offiziere): +1 Flottenslot, +2 % Energie,
    +2 % Minen, +1 Spionagestufe; auf der Offiziersseite beschrieben.
  - Tests für alle Boni, den Ingenieur in einem Kampf und die Priorität;
    im Browser gesendet.
- ✅ **Flottenflucht** (taktischer Rückzug des Originals; Forum-Regeln,
  `FleetEscape`, `FleesFromBattle`): Die Schiffe des Verteidigers auf dem
  Planeten fliehen vor dem Kampf, wenn die Angreifer mindestens 5-mal so
  stark sind (mit Admiral 3-mal). Stärke sind die Kosten der Schiffe im
  Kampf, zivile Schiffe zählen ein Viertel, Verteidigung gar nicht.
  Todessterne, Sonden, Solarsatelliten und Crawler bleiben; stationierte
  fremde Flotten fliehen nicht. Die Flucht kostet das 1,5-Fache des
  Deuteriums für einen Flug zur Nachbarposition; fehlt es, bleibt die
  Flotte. Nur innerhalb der Noob-Schutz-Grenze (Punkte unter
  `noobprotectiontime`), nicht bei Inaktiven; in den Einstellungen
  abschaltbar (etwa für Mondschüsse). Der Kampfbericht nennt die
  geflohenen Schiffe. Nicht bei ehrenhaften Kämpfen. Tests mit echten
  Angriffen auf MariaDB.
- ✅ **Ehrensystem** (owiki „Ehrenpunkte“, OGame-Wiki „Honor Points“;
  `HonorService`, `users_statistics.user_statistic_honor_*`):
  - Ein Kampf ist **neutral** gegen Inaktive, Allianzmitglieder und
    Buddys (im AKS nur, wenn alle Angreifer es sind), **ehrenhaft**, wenn
    das Ziel mindestens die Hälfte der Militärpunkte der Angreifer hat
    (oder höchstens 10 weniger) oder ein Bandit ist, sonst **ehrenlos**.
  - Ehrenpunkte = abgerundet(Einheiten^0,9 / 1.000): Im ehrenhaften Kampf
    gewinnen die Angreifer für die zerstörten militärischen Einheiten des
    Verteidigers, im ehrenlosen verlieren sie (zivile Schiffe halb, dazu
    die halbe Beute); der Verteidiger gewinnt für die zerstörten
    militärischen Schiffe der Angreifer und verliert nie. Die Angreifer
    teilen nach dem Wert ihrer Flotten.
  - Beute: 50 %, im ehrenhaften Kampf 75 %, von Banditen 100 % (der
    Entdecker-Bonus gegen Inaktive bleibt).
  - Titel nach Rang: Starlord (Top 250, ab 500), Imperator (Top 100, ab
    2.500), Groß-Imperator (Top 10, ab 15.000); Bandit (letzte 250, ab
    -500), Banditenlord (letzte 100, ab -2.500), Banditenkönig (letzte 10,
    ab -15.000).
  - Galaxie: ehrenhafte Ziele tragen „eZ“; Highscore: neue Kategorie
    „Ehre“ mit Titeln; der Statistik-Lauf führt den Ehrenrang.
  - **Nicht übernommen**: Vogelfreiheit (Angriff auf viel Stärkere hebt
    den eigenen Noob-Schutz auf) und die Rangbedingung „höchstens 100
    Ränge dahinter“.
  - Tests mit echten Angriffen auf MariaDB (ehrenhaft, ehrenlos, Bandit,
    neutral); im Browser geprüft.
- ✅ **Militär-Ranglisten** wie im Original: „Militär gebaut“ (Punkte
  fertiger Schiffe und Verteidigung), „Militär zerstört“ (was die
  Gegenseite im Kampf verlor, geteilt nach dem Wert der eigenen Flotten)
  und „Militär verloren“; mit Rängen im Statistik-Lauf und eigenen
  Highscore-Kategorien (nur für Spieler, wie „Ehre“). Die Kategorie
  „Wirtschaft“ bleibt die Gebäudepunkte. Test mit einem echten Angriff
  und Statistik-Lauf auf MariaDB.
- ✅ **Planet umsiedeln** (owiki „Umsiedeln“, OGame-Forum-FAQ;
  `RelocationService`, `PlanetMover`, `planets.planet_relocate_*`,
  Option `relocation_price`):
  - Im Planetenmenü wählt der Spieler eine freie Position desselben
    Bereichs (1–3, 4–12, 13–15) in beliebigem System. Die Position ist
    24 Stunden reserviert (auch Kolonisieren scheitert dort), dann ziehen
    Planet und Mond mit Gebäuden, Verteidigung und Rohstoffen um.
  - 240.000 Dunkle Materie müssen beim Start da sein und werden erst beim
    Umzug abgezogen. Abbruch ist jederzeit kostenlos.
  - Beim Start und beim Umzug dürfen Planet und Mond nicht bauen, nicht
    forschen, keine Werftaufträge haben und keine eigenen Flotten von
    oder zu ihnen fliegen. Sonst, oder im Urlaubsmodus, wird die
    Umsiedlung abgebrochen; der Spieler bekommt eine Nachricht mit dem
    Grund.
  - Beim Umzug fliegen die Schiffe von Planet und Mond als Stationierung
    mit normaler Flugzeit und ohne Treibstoff hinterher; Solarsatelliten
    und Crawler ziehen mit. Fremde haltende Flotten kehren heim. Das
    Trümmerfeld bleibt an der alten Position (verfällt). Das Sprungtor
    des Mondes ruht 24 Stunden. Die Temperatur richtet sich nach der neuen
    Position, die Felder bleiben.
  - Eine Flotte, deren Startplanet nicht mehr existiert, kann nicht
    zurückgerufen werden; sonst gingen ihre Schiffe an der leeren
    Position verloren.
  - **Annahmen**: Ein noch nicht reparierendes Wrackfeld verfällt beim
    Umzug. Eine laufende Raumdock-Reparatur oder wartende reparierte
    Schiffe verhindern das Umsiedeln. Umzüge werden im Spiel-Tick
    abgeschlossen (wie Flotten), nicht sekundengenau.
  - Tests auf MariaDB (Bereich, belegt, reserviert, DM, Aufträge,
    Flotten, Urlaubsmodus, kompletter Umzug mit Landung der Schiffe); im
    Browser gestartet, abgebrochen und durchgeführt.
- ✅ **General: Flottentempo in 5-%-Schritten** (V7): Generäle wählen 5 %
  bis 100 % in 5-%-Schritten, alle anderen weiter in 10-%-Schritten
  (`PlayerClass::speedSteps`). Das Tempo läuft intern weiter in Zehnteln
  (9,5 = 95 %), auch durch die vorhandene Flugzeit-Berechnung im
  Browser. Fleet3 nimmt nur die Stufen der eigenen Klasse an, ein
  eingeschmuggeltes 95 % eines Nicht-Generals führt zurück zu Schritt 1.
  Im Browser mit 95 % gesendet (Flugzeit wie angezeigt).
- ✅ **M.O.O.N.S.** (Gegenstand des Originals, OGame-DE-Forum): ein
  Mondschuss ohne Flotte auf einen eigenen Planeten ohne Mond. Mit 20 %
  entsteht ein Mond in der Größe eines Kampfmondes mit 20 % Chance, ein
  Trümmerfeld entsteht nicht; der Gegenstand ist auch ohne Mond
  verbraucht. Im Shop für 50.000 Dunkle Materie (**Annahme**: Der
  Original-Preis war nicht belegt). Wird nicht versteigert und nicht auf
  Expeditionen gefunden. Tests; im Browser gekauft und eingesetzt.

Offen:

- (nichts aus dem Smoke-Test)
- ℹ️ Erwartete Meldungen im Crawl, keine Fehler:
  `game.php?page=combatreport` ohne Bericht-Id liefert absichtlich 404;
  unter `admin/errors` stehen alte Log-Einträge (Datenbank zeitweise nicht
  erreichbar, Willkommens-Mail ohne Mailserver in der Testumgebung).
- ℹ️ Neue Allianzen sind standardmäßig geschlossen
  (`alliance_request_notallow = 0` heißt trotz des Namens „keine
  Bewerbungen“). So ist es durchgängig im Code, also eine
  Design-Entscheidung.

