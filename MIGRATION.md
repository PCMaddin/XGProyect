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
| Analyse-Schuld | — | 729 PHPStan- + 295 PHPMD-Einträge in Baselines unterdrückt |

> **Stand:** `legacy/app/Http/Controllers/Game/` ist **leer** — alle 38 Spielseiten
> laufen nativ. Ausgehend von 1.337 PHPStan- / 682 PHPMD-Einträgen wurden über alle
> Wellen 608 PHPStan- und 387 PHPMD-Einträge abgebaut. Jede Migration ist lokal mit
> PHPStan Level 9, PHPMD und der PHPUnit-Suite (554 Tests) verifiziert.
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
| SecurePageLib (45 Z., Legacy-Input-Sanitizer) | ✅ migriert | nur von `Core\Common` (Legacy-Bootstrap) genutzt. Nach `App\Libraries`, `$instance`/`validate` typisiert, `else` durch Guard ersetzt. **Bugfix:** die rekursive Array-Sanitisierung nummerierte verschachtelte Arrays neu (`$value[$c]`) → verschachtelte Formular-Eingaben wurden still korrumpiert; jetzt bleiben die Keys erhalten. Non-Scalar-Blätter werden zu `''`. 3 Tests. 3 PHPStan- + 3 PHPMD-Einträge abgebaut |
| StatisticsLibrary (670 Z., Punkte/Ränge-Batch) | ✅ migriert | war bereits gut typisiert (nur 2 PHPStan-Einträge); 5 native + 3 Legacy-Aufrufer (`UpdatesLibrary`, `Missions\Missions`, `Missions\Colonize`). Nach `App\Libraries` portiert: `$time`/`makeStats`-Rückgabe getippt (präzise Shape → Consumer bleibt typsicher), `calculatePoints` akzeptiert `int|string` (ResearchQueue gibt String), Div-durch-0-Guard, `self::`→`$this->`, auto-vivifiziertes `$rank`/`$result` initialisiert, toter `$update` entfernt. Die zwei großen Ranking-Batches (`makeUserRank`/`makeAllyRank`) 1:1 erhalten, Komplexität per `@SuppressWarnings` statt Baseline. Bestehender `StatisticsLibraryTest` umgehängt. 2 PHPStan- + 11 PHPMD-Einträge abgebaut (+ 2 veraltete Aufrufer-Einträge) |
| Users\Notes, Game\Preferences, Planet\Ships | 🗑️ gelöscht (tot) | 0 Referenzen (Ships-„Aufrufer" im Scan waren False-Matches auf den `ShipsEnumerator as Ships`-Alias); Notes/Preferences durch Eloquent-Modelle ersetzt; latente Bugs (null statt Entity, `[0]` auf leerer Menge) mit-entfernt |
| Buildings/ (Building, Queue, QueueElements) + QueueTest | 🗑️ gelöscht (tot) | String-basierte Alt-Bau-Queue, in Produktion durch `BuildingQueue`-Modell + `BuildingQueueService` ersetzt (0 Referenzen). `QueueTest` testete nur die tote String-Logik; die moderne Sequenzierung deckt bereits `QueueSequenceServiceTest` ab — kein Verlust an Live-Coverage. 29 Baseline-Einträge abgebaut |
| _Nachtrag:_ `BuildingQueueService` | ✅ getestet | Der dokumentierte Test-Gap ist geschlossen: neue **DB-Test-Infrastruktur** (In-Memory-SQLite + Install-Migrationen via `DatabaseTestCase`) + 6 Charakterisierungs-Tests (add/Charge, Positions-Increment, Queue-Cap, cancelFirst/Refund, getQueueData) |

Reihenfolge nach Sauberkeit (Blatt-Kandidaten mit den wenigsten Legacy-Referenzen
zuerst). Für Libs, die die noch lebende Engine mitbenutzt (`Formulas`,
`NoobsProtectionLib`), gilt das **Fassaden-Muster**: die Logik zieht nativ um, eine
dünne `App\Libraries\…`-Brücke bedient die Engine weiter, bis auch diese migriert
ist. Noch offen an der Missions-Engine bzw. am Tick: `PlanetLib`,
`UpdatesLibrary`, `Users`, `Functions` sowie die Missions-/BattleEngine.

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
