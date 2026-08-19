# Migrations-Roadmap: `legacy/` → `app/`

> Stand des Audits: XG Proyect befindet sich mitten in einem **Strangler-Fig-Umbau**
> vom alten prozeduralen XGP-Code (`legacy/`) nach modernem Laravel 12 (`app/`).
> Rund 40 % der Game-Module sind bereits portiert. Dieses Dokument beschreibt den
> Weg, die Migration **fertigzustellen** — kein Rewrite von Null.

## Ausgangslage

| | Neu (`app/`) | Legacy (`legacy/`) |
|---|---|---|
| Game-Controller | **38 migriert — Controller-Schicht komplett** | 0 verbleibend |
| Bibliotheken | 11 migriert + 5 tote gelöscht | Welle 5 läuft |
| Architektur | Eloquent, Services, FormRequests, typisiert, PHPStan Level 9 | Raw-SQL, Templates, `$_POST`, globale Konstanten |
| Analyse-Schuld | — | 849 PHPStan- + 401 PHPMD-Einträge in Baselines unterdrückt |

> **Stand:** `legacy/app/Http/Controllers/Game/` ist **leer** — alle 38 Spielseiten
> laufen nativ. Ausgehend von 1.337 PHPStan- / 682 PHPMD-Einträgen wurden über alle
> Wellen 360 PHPStan- und 206 PHPMD-Einträge abgebaut. Jede Migration ist lokal mit
> PHPStan Level 9, PHPMD und der PHPUnit-Suite (447 Tests) verifiziert.
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
| Users\Notes, Game\Preferences | 🗑️ gelöscht (tot) | 0 Referenzen; durch Eloquent-Modelle `App\Models\Notes`/`Preferences` ersetzt; latente Bugs (null statt Entity, `[0]` auf leerer Menge) mit-entfernt |
| Buildings/ (Building, Queue, QueueElements) + QueueTest | 🗑️ gelöscht (tot) | String-basierte Alt-Bau-Queue, in Produktion durch `BuildingQueue`-Modell + `BuildingQueueService` ersetzt (0 Referenzen). `QueueTest` testete nur die tote String-Logik; die moderne Sequenzierung deckt bereits `QueueSequenceServiceTest` ab — kein Verlust an Live-Coverage. 29 Baseline-Einträge abgebaut |
| _Nachtrag:_ `BuildingQueueService` | ✅ getestet | Der dokumentierte Test-Gap ist geschlossen: neue **DB-Test-Infrastruktur** (In-Memory-SQLite + Install-Migrationen via `DatabaseTestCase`) + 6 Charakterisierungs-Tests (add/Charge, Positions-Increment, Queue-Cap, cancelFirst/Refund, getQueueData) |

Reihenfolge nach Sauberkeit (Blatt-Kandidaten mit den wenigsten Legacy-Referenzen
zuerst). Nicht sauber lösbar, solange die Engine lebt: `Formulas`, `PlanetLib`,
`StatisticsLibrary`, `NoobsProtectionLib`, `DevelopmentsLib` (Alias-Aufruf in
`UpdatesLibrary`) — diese hängen an der Missions-Engine bzw. am Tick.

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
