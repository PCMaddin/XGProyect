# Migrations-Roadmap: `legacy/` → `app/`

> Stand des Audits: XG Proyect befindet sich mitten in einem **Strangler-Fig-Umbau**
> vom alten prozeduralen XGP-Code (`legacy/`) nach modernem Laravel 12 (`app/`).
> Rund 40 % der Game-Module sind bereits portiert. Dieses Dokument beschreibt den
> Weg, die Migration **fertigzustellen** — kein Rewrite von Null.

## Ausgangslage

| | Neu (`app/`) | Legacy (`legacy/`) |
|---|---|---|
| Game-Controller | 33 migriert | 5 verbleibend |
| Architektur | Eloquent, Services, FormRequests, typisiert, PHPStan Level 9 | Raw-SQL, Templates, `$_POST`, globale Konstanten |
| Analyse-Schuld | — | 1.055 PHPStan- + 510 PHPMD-Einträge in Baselines unterdrückt |

> **Stand:** ausgehend von 1.337 PHPStan- / 682 PHPMD-Einträgen wurden beim Migrieren
> der bisherigen Module (Welle 1 komplett + Buddies, Messages, Alliance, Overview,
> Phalanx, Shipyard, Defenses, Galaxy, Movement, Fleet1) 282 PHPStan- und 172 PHPMD-
> Einträge abgebaut. Ab der Verfügbarkeit der Tools ist jede Migration lokal mit
> PHPStan Level 9 und der PHPUnit-Suite verifiziert.

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
| Flotten-Assistent | Fleet1 ✅, Fleet2–4 (393/581/826) | Fleet1 portiert; Fleet4 = Commit-Schritt (`die`-Codes, wie Galaxy) |
| ACS | Federation (428) | offen, SQL-Injections zu härten |

Reihenfolge: Fleet2 → Fleet3 → **Fleet4** (kritisch) → Federation. Danach ist
`legacy/app/Http/Controllers/Game/` leer.

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
