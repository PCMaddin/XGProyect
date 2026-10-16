# Migrations-Roadmap: `legacy/` → `app/`

> Stand des Audits: XG Proyect befindet sich mitten in einem **Strangler-Fig-Umbau**
> vom alten prozeduralen XGP-Code (`legacy/`) nach modernem Laravel 12 (`app/`).
> Rund 40 % der Game-Module sind bereits portiert. Dieses Dokument beschreibt den
> Weg, die Migration **fertigzustellen** — kein Rewrite von Null.

## Ausgangslage

| | Neu (`app/`) | Legacy (`legacy/`) |
|---|---|---|
| Game-Controller | **38 migriert — Controller-Schicht komplett** | 0 verbleibend |
| Bibliotheken | 14 migriert + 6 tote gelöscht | Welle 5 läuft |
| Architektur | Eloquent, Services, FormRequests, typisiert, PHPStan Level 9 | Raw-SQL, Templates, `$_POST`, globale Konstanten |
| Analyse-Schuld | — | 693 PHPStan- + 267 PHPMD-Einträge in Baselines unterdrückt |

> **Stand:** `legacy/app/Http/Controllers/Game/` ist **leer** — alle 38 Spielseiten
> laufen nativ. Ausgehend von 1.337 PHPStan- / 682 PHPMD-Einträgen wurden über alle
> Wellen 644 PHPStan- und 415 PHPMD-Einträge abgebaut. Jede Migration ist lokal mit
> PHPStan Level 9, PHPMD und der PHPUnit-Suite (563 Tests) verifiziert.
>
> **Verbleibende Legacy-Bibliotheken** (kein Controller mehr, nur noch Backend):
> die Missions-/Kampf-Engine (`Missions`, `BattleEngine`, `MissionControlLib`) und
> Update-/Objekt-Bibliotheken (`UpdatesLibrary`, `Objects`, `Fleets`, `Users` …),
> die vom Tick und den nativen Controllern genutzt werden. Das ist die nächste,
> entkoppelte Refactoring-Welle ohne Zeitdruck.

**Bereits migriert** (`app/Http/Controllers/Game/`): Buildings, Research, Supplies,
Facilities, Preferences, Empire, Technologytree, Technologydetails, Combatreport,
Playerprofile, Changenick, Notices, Banned, Changelog, Logout.

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

## Welle 2 — Geteilter Berechnungs-Layer  🟡 Enabler, keine UI

**Der wichtigste Schritt.** Vor den schweren Controllern die geteilten Libraries als
typisierte Services nach `app/Services/Game/Formulas/` portieren (dort liegen schon einige).

- `Formulas` (281 Z., 11 Nutzer) → Service
- `DevelopmentsLib` (296 Z.), `FleetsLib` (500 Z.) → Services
- Danach die 33 `app/`-Referenzen auf Legacy-Libs sauber umhängen

## Welle 3 — Sozial & Kommunikation  🟡 mittel

| Modul | Status | Notiz |
|---|---|---|
| Buddies | ✅ migriert | Magic-Dispatch entfernt, Null-User-Bug behoben |
| Messages | ✅ migriert | SQL-Injection in Bulk-Delete behoben |
| Alliance | ✅ migriert | in 4 Etappen (public → writes → admin → Finale); 4 SQL-Injections + Template-Typo + Transfer-Key behoben |
| Federation | ⏭️ nach Welle 4 | ACS, hängt an der Fleet-Logik |

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

## Welle 5 — Leaf-Bibliotheken 🟢 laufend

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
| Alliance\Ranks (159 Z., Allianz-Ränge) | ✅ migriert | nach `App\Libraries\Alliance\Ranks` (final, präzise Shape `array{rank: string, rights: array<int, int>}` pro Rang-Id), 4 native Aufrufer umgehängt und ihre Defensiv-Checks entfernt. **`die()` entfernt** (kaputtes JSON → keine Ränge, leerer Name → nichts angelegt). **Bugs gefixt:** eine negative Rang-Id wurde auf 0 geklemmt und löschte per `deleteRankById` den **Gründer-Rang** (im Spiel per manipuliertem `d=-1` erreichbar); `editRankById` mit Id > Anzahl prüfte die geklemmte Id, schrieb aber die ungeklemmte und legte so einen Rang ohne Namen an; Rang-Namen wurden SQL-escaped gespeichert, obwohl sie gebunden geschrieben werden (`O'Neil` erschien als `O\'Neil`). Unbekannte Ids werden jetzt ignoriert. 9 Tests; 14 PHPStan- + 10 PHPMD-Einträge abgebaut. **Offen (Spiellogik):** Löschen eines Rangs verschiebt die Ids der folgenden Ränge, `users.ally_rank_id` der Mitglieder wird aber nicht nachgezogen |
| Combatreport\Report, Entity\{Report,Notes,Preferences,Ships,User}Entity, ReportStatusEnumerator, Helpers\ArraysHelper, `resources/views/legacy_NotInUse/` | 🗑️ gelöscht (tot) | 0 Referenzen (der Kampfbericht läuft nativ über `CombatreportController`; die Views wurden nur in auskommentiertem Code erwähnt, der mit entfernt ist). 22 PHPStan- + 9 PHPMD-Einträge abgebaut |
| Core\Objects (deprecated Objekt-Konfig) | ✅ getippt | die überladenen Getter (`getObjects($id = null)` usw.) behalten ihre API, liefern aber über **bedingte Rückgabetypen** präzise Typen (`($objectId is int ? string : array<int, string>)`); Properties mit Shapes (`CombatSpec`, `ProductionSpec` inkl. Formel-Closures). Collections über `all()` statt `toArray()`. In 11 Aufrufern (Fleet1–4, Galaxy, Movement, Overview, Premium, Resourcesettings, Shipyard, Missile) fallen die `is_array`/`is_string`/`is_numeric`-Schutzabfragen weg. `UpdatesLibrary` normalisiert die Formel-Argumente vor dem Aufruf. 12 PHPStan- + 1 PHPMD-Eintrag abgebaut |
| Core\Template (Blade-Helfer) | ✅ migriert | nach `App\Libraries\Template` (4 native Aufrufer). `legacyView()` ist `never`, `render()` liefert immer `string`. **Bug gefixt:** bei fehlender View kam `null` zurück, `jsReady(Template::render(...))` (FleetsLib-Popup) warf dann einen `TypeError`. `LegacyView` nimmt jetzt das View-Interface (`View::make()` liefert dieses). 3 Tests; 6 PHPStan- + 2 PHPMD-Einträge abgebaut |
| Core\Entity + 8 Entities | ✅ getippt | `Entity` normalisiert die DB-Zeile auf `array<string, int\|float\|string\|bool\|null>` (Alias `Column`); die Unterklassen-Konstruktoren riefen nur `parent::__construct()` auf und sind weg. Die Roh-Getter deklarierten `@return string`, lieferten aber den DB-Wert (meist `int`); jetzt ehrlich `Column`. 11 PHPStan-Einträge abgebaut |
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

## Prinzipien für jede Migration

1. **Ein Modul = ein PR**, Legacy-Datei am Ende löschen (kein Parallelbetrieb).
2. **Baseline schrumpfen**: pro portiertem Modul die zugehörigen PHPStan/PHPMD-Einträge
   entfernen — messbarer Fortschritt.
3. **Read-only vor Schreib-Logik** innerhalb jeder Welle.
4. Bei Spiellogik: **erst ein Test, der das Legacy-Verhalten festnagelt**, dann portieren.

---

## Begleitende Audit-Findings (unabhängig von der Welle einplanen)

- 🔴 **Trader-Crash**: `legacy/app/Http/Controllers/Game/TraderLayerController.php` —
  `getMode()` (Z. 232) ruft dynamisch `build{Mode}Section()` auf, aber nur
  `buildResourcesSection()` existiert. `?mode=traderAuctioneer|traderScrap|traderImportExport`
  → Fatal Error. Zusätzlich ist die gesamte Handelslogik (Z. 34–215) auskommentierter toter Code.
- 🟡 **Mass-Assignment**: `authlevel` liegt in `User::$fillable` — aktuell nicht ausnutzbar
  (Admin-Pfade nutzen FormRequests), aber härtungswürdig.
- 🟡 **Admin-Schreibschleifen** (`UserPlanetTrait`, `UserProgressController`) nehmen
  Request-Feldnamen per Präfix-Whitelist als Spaltennamen — strikte Allowlist wäre robuster.
- 🟢 **`.env.example`** liefert einen festen `APP_KEY` mit — leeren, um versehentliche
  Übernahme zu vermeiden.

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

## TODO: Übersetzungslücken (`lang/en` ↔ `lang/es`)

Abgleich aller Sprachdateien (Stand Welle 6). **Wichtig:** ein *fehlender*
Schlüssel fällt auf Englisch zurück (`fallback_locale = en`), ein *leerer*
String (`''`) aber nicht: der Spieler bekommt eine leere Meldung.

- 🟡 **`es/game/expedition.php` — leer und im Spiel genutzt** (spanische
  Spieler sehen leere Expeditionsberichte): `exp_new_ships_5`,
  `exp_new_resources_3/4`, `exp_dm_2..5`, `exp_delay_2..5`, `exp_faster_1/2`,
  `exp_nothing_4..6`, `exp_lost_1`.
- 🟢 **`es/game/expedition.php` — leer, derzeit ungenutzt** (für noch nicht
  implementierte Expeditions-Ergebnisse bzw. höhere Varianten-Nummern):
  `exp_new_ships_6/7`, `exp_primitive_enemy_5/6/8`, `exp_advanced_enemy_2`,
  `exp_found_object_1/2`, `exp_nothing_7` und `exp_nothing_9..16`.
- 🟢 **`en/game/expedition.php`**: `exp_nothing_8` leer, `exp_primitive_enemy_7`
  fehlt (beide ungenutzt).
- 🟢 **Fehlen in `es`** (Fallback auf Englisch, also nur unübersetzt):
  `admin/global.gn_info_title`, `game/planetlayer.rp_wrong_pass`,
  `install/install.admin_create_success`, `install/install.admin_create_fail`.

Vorschlag: die leeren Strings entweder übersetzen oder entfernen (dann greift
der englische Fallback), und einen Test ergänzen, der beide Sprachen auf gleiche
Schlüssel und keine leeren Werte prüft.
