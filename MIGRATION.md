# Migrations-Roadmap: `legacy/` → `app/`

> Stand des Audits: XG Proyect befindet sich mitten in einem **Strangler-Fig-Umbau**
> vom alten prozeduralen XGP-Code (`legacy/`) nach modernem Laravel 12 (`app/`).
> Rund 40 % der Game-Module sind bereits portiert. Dieses Dokument beschreibt den
> Weg, die Migration **fertigzustellen** — kein Rewrite von Null.

## Ausgangslage

| | Neu (`app/`) | Legacy (`legacy/`) |
|---|---|---|
| Game-Controller | 15 migriert | 25 verbleibend |
| Architektur | Eloquent, Services, FormRequests, typisiert, PHPStan Level 9 | Raw-SQL, Templates, `$_POST`, globale Konstanten |
| Analyse-Schuld | — | 1.337 PHPStan- + 682 PHPMD-Einträge in Baselines unterdrückt |

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

## Welle 1 — Blattmodule / Quick-Wins  🟢 niedriges Risiko

Selbständige Module mit wenig Abhängigkeiten. Ziel: Blaupause & Momentum. Der
Trader-Bug (siehe unten) wird hier miterledigt.

| Modul | Zeilen | Kernabhängigkeit | Notiz |
|---|---|---|---|
| Trader + TraderOverview + TraderResources + TraderLayer | 27–263 | `Formulas` | **Fixt den Audit-Bug**; großteils toter Code → viel entfällt |
| Defenses | 29 | eigene Lib | winzig, idealer Start |
| Chat | 119 | — | isoliert |
| Fleetshortcuts | 255 | — | isoliert |
| Search | 270 | `Formulas` | Galaxie-Suche, read-only |
| Highscore | 283 | `StatisticsLibrary` | read-only |
| Premium | 164 | `Premium`-Lib | teils in `app/Models/Premium` vorhanden |
| Planetlayer / Resourcesettings | 184 / 378 | `Formulas`, `DevelopmentsLib` | Planeten-Einstellungen |

## Welle 2 — Geteilter Berechnungs-Layer  🟡 Enabler, keine UI

**Der wichtigste Schritt.** Vor den schweren Controllern die geteilten Libraries als
typisierte Services nach `app/Services/Game/Formulas/` portieren (dort liegen schon einige).

- `Formulas` (281 Z., 11 Nutzer) → Service
- `DevelopmentsLib` (296 Z.), `FleetsLib` (500 Z.) → Services
- Danach die 33 `app/`-Referenzen auf Legacy-Libs sauber umhängen

## Welle 3 — Sozial & Kommunikation  🟡 mittel

| Modul | Zeilen | Notiz |
|---|---|---|
| Messages | 529 | `Messenger`-Lib |
| Buddies | 400 | schon sauber (int-casts/binding) → gute Vorlage |
| Federation | 428 | ACS, hängt an Fleet-Logik → evtl. nach Welle 4 |
| Alliance | 1482 | größter Controller — **aufteilen** in mehrere Controller/Services |

## Welle 4 — Gameplay-Kern  🔴 hohes Risiko, zusammen & mit Tests

Der interdependente Kern: Flotten senden → ankommen → kämpfen/spionieren/kolonisieren.
Nicht einzeln migrierbar.

| Cluster | Module | Zeilen | Engine darunter |
|---|---|---|---|
| Übersicht/Werft | Overview (446), Shipyard (562) | 1008 | `UpdatesLibrary` (925), `DevelopmentsLib` |
| Galaxie/Phalanx | Galaxy (846), Phalanx (230), Movement (303) | 1379 | `GalaxyLib` (721), `FleetsLib` |
| Flotten | Fleet1–4 (328/393/581/826) | 2128 | `Missions` (Attack/Spy/Destroy/Expedition), `BattleEngine` |

→ Hier steckt die über Jahre erprobte Spiellogik (Timing, Balancing, Kampfrunden).
**Erst Engine (Missions + BattleEngine) als Service, dann Controller**, mit
Charakterisierungs-Tests vorher.

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
