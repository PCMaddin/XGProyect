# Produktionsbetrieb mit Docker

Dieses Handbuch bringt das Spiel mit einem Befehl auf einen eigenen Server:
HTTPS mit automatischem Zertifikat, mehrere PHP-Container, MariaDB, Redis
und die Spielzeit per Scheduler. Updates laufen ohne Ausfall; ein kaputtes
Release geht nie live.

Für die Entwicklung bleibt `docker-compose.yml` im Projektordner (Laravel
Sail). Alles für den Server liegt in `deploy/`.

## Was läuft

| Dienst | Aufgabe |
|---|---|
| `web` | Caddy: HTTPS (Let's Encrypt, erneuert sich selbst), HTTP/3, statische Dateien, verteilt PHP-Anfragen auf die App-Container |
| `app` | php-fpm 8.4 mit OPcache und JIT, mehrere nebeneinander (`APP_REPLICAS`) |
| `scheduler` | `php artisan schedule:work`: Spielzeit aller Universen (`xgp:tick`), Monatsstart, WAX, Betrugssuche |
| `db` | MariaDB 11.4 |
| `redis-cache` | Cache, darf bei vollem Speicher vergessen (`allkeys-lru`, ohne Festplatte) |
| `redis-sessions` | Anmeldungen, wird nie verdrängt und jede Sekunde auf die Platte geschrieben |

Die Container laufen ohne Root-Rechte (php als `www-data`). Von außen
erreichbar sind nur die Ports 80 und 443 des Webservers. Datenbank und
Redis hängen nur im internen Netz.

Daten liegen in Docker-Volumes: `db` (alle Universen und die
Basisdatenbank), `sessions`, `storage` (Logs, Admin-Backups), `caddy-data`
(Zertifikate).

## Voraussetzungen

- Ein Linux-Server mit Docker Engine und dem Compose-Plugin (`docker
  compose version` ≥ 2.20), `git` und `openssl`.
- Der DNS-Eintrag der Domain zeigt auf den Server; Ports 80 und 443 sind
  offen (Let's Encrypt prüft über Port 80).
- Größe: etwa 2 vCPU je 1.000 gleichzeitig spielenden Spielern, dazu RAM
  für die Datenbank (rund 2,5 GB je 1 Mio. Spieler). Zum Start reicht ein
  Server mit 8 vCPU und 16 GB.

## Erste Einrichtung

```bash
git clone <repository> xgproyect && cd xgproyect
deploy/deploy.sh
```

Der erste Aufruf legt `deploy/.env` an und hört auf. Darin eintragen:

- `XGP_DOMAIN` und `APP_URL` (`https://` + Domain),
- `ACME_EMAIL` (Let's Encrypt schreibt dorthin vor dem Ablauf eines
  Zertifikats),
- den Mailserver (`MAIL_…`),
- bei Bedarf weitere Schlüssel aus `../.env.example` (Stripe, WAX,
  Monatsstart, Betreiberbereich).

Dann noch einmal, diesmal mit dem Administrator des Spiels:

```bash
deploy/deploy.sh --admin-name=Admin --admin-email=du@example.com
```

Das Skript fragt nach dem Passwort und
- erzeugt einmalig `APP_KEY`, `XGP_SECRETWORD` und die Datenbank-Passwörter
  (in `deploy/.env`, nur für root lesbar),
- baut das Image,
- installiert das Spiel (`php artisan xgp:install`, nur in eine leere
  Datenbank; bricht es ab, ist die Datenbank danach wieder leer),
- spielt die Migrationen ein,
- startet App-Container, Scheduler und Webserver.

Danach:

```bash
cd deploy
docker compose exec app php artisan xgp:operator:create   # Zugang zu /operator
docker compose exec app php artisan xgp:instance:create nov --name="November" …
```

Universen, Betreiber und alle anderen Befehle aus
[universes.md](universes.md) laufen so mit `docker compose exec app php
artisan …`. Der Datenbank-Benutzer darf die Datenbanken der Universen
(`xgp_uni_<slug>`) anlegen; das setzt `deploy.sh` bei jedem Lauf.

## Updates

```bash
git pull
deploy/deploy.sh
```

Ablauf:

1. Neues Image bauen. Die Konfiguration kommt erst beim Start aus
   `deploy/.env` und steckt nie im Image.
2. Die Basisdatenbank migrieren (`xgp:versions:migrate-base`; ist sie
   selbst ein Universum auf einer älteren Version, bleibt sie unberührt),
   die neue Version in das Volume `versions` kopieren
   (`xgp:versions:sync`), dann die Migrationen der Universen auf der
   laufenden Version einspielen, während die alte Version
   weiterläuft. Migrationen müssen deshalb zur laufenden Version passen:
   erst hinzufügen, entfernen erst im nächsten Release. Universen auf einer
   älteren Version bleiben darauf, bis `xgp:instance:upgrade` sie bewegt
   (siehe [universes.md](universes.md), „Versionen je Universum“); dafür
   braucht jedes Release eine höhere Nummer in `config/version.php`.
3. Neue App-Container neben den alten starten. Jeder rendert zuerst
   intern die Startseite und nimmt erst danach Anfragen an. Ein Container,
   der das nicht schafft, bekommt nie eine Anfrage.
4. Erst wenn alle neuen bereit sind, beenden die alten ihre laufenden
   Anfragen und gehen. Caddy findet die Container über das interne DNS und
   wiederholt eine Anfrage, die einen gerade stoppenden Container trifft,
   bei einem anderen.
5. Scheduler neu starten, Caddy-Konfiguration neu laden, alte Images bis auf
   die letzten drei Releases löschen.

Getestet: 19.244 Anfragen während eines Wechsels, keine fehlgeschlagen.
Ein Release, dessen Seiten mit Fehler 500 enden, wurde nach 9 Sekunden
abgewiesen; die alte Version lief ohne einen Fehler weiter.

**Ein fertiges Image ausrollen** (z. B. in der CI gebaut und in eine
Registry geschoben):

```bash
XGP_IMAGE=registry.example.com/xgproyect:1.2.0 deploy/deploy.sh
```

**Zurück zum vorigen Release:** `XGP_IMAGE=xgproyect-app:<tag>
deploy/deploy.sh` mit einem Tag aus `deploy/.releases`. Die Datenbank geht
dabei nicht zurück (siehe Migrationen oben).

**Hinter einem Proxy, der TLS neu signiert** (Firmennetz):
`EXTRA_CA=/pfad/zur/ca.crt DOCKER_BUILD_NETWORK=host deploy/deploy.sh`.
Die CA gilt nur während des Builds und landet nicht im Image.

## Einstellungen in `deploy/.env`

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `APP_REPLICAS` | 2 | App-Container nebeneinander |
| `PHP_FPM_CHILDREN` | 16 | PHP-Prozesse je Container (etwa 4 je vCPU, je bis zu 256 MB) |
| `DB_BUFFER_POOL` | 2G | Cache der Datenbank, etwa so groß wie die Daten |
| `REDIS_CACHE_MEMORY` | 512mb | Speicher des Caches |
| `HTTP_PORT`, `HTTPS_PORT` | 80, 443 | Ports des Webservers |
| `XGP_PAYMENTS_TEST`, `XGP_WAX_NETWORK` | true, testnet | Alpha: nur Testzahlungen (siehe [universes.md](universes.md)) |

Nach einer Änderung `deploy/deploy.sh` erneut ausführen.

## Im Betrieb

```bash
cd deploy
docker compose ps                         # Zustand, „healthy“ bei app und web
docker compose logs -f web                # Zugriffe
docker compose logs -f scheduler          # xgp:tick jede Minute
docker compose exec app tail -f storage/logs/xgproyect.log
docker compose exec app php artisan xgp:instance:list
```

- **Logs** der Container rotieren (5 × 20 MB je Dienst). Die Logs des
  Spiels liegen im Volume `storage` unter `storage/logs/`.
- **Backups:** `XGP_BACKUP_REPOSITORY` und `XGP_BACKUP_PASSWORD` in
  `deploy/.env` setzen. Der Scheduler sichert dann jede Nacht alle
  Datenbanken verschlüsselt auf einen zweiten Server (restic steckt im
  Image). Wiederherstellen und Einrichten: [universes.md](universes.md),
  Abschnitt „Backups auf einem zweiten Server“.

  Dazu `deploy/.env` getrennt vom Server aufbewahren: Ohne `APP_KEY` und
  `XGP_SECRETWORD` sind Sitzungen und gespeicherte Geheimnisse (z. B.
  Zwei-Faktor) nicht mehr lesbar, ohne `XGP_BACKUP_PASSWORD` das Backup.
- **Neustart des Servers:** Alle Dienste starten von selbst wieder
  (`restart: unless-stopped`).

## Lasttest vor dem Start

Wie viele Spieler trägt der Server? Das misst du auf dem Zielserver selbst,
bevor Tester kommen. Der Test läuft in einem eigenen Universum, nie in einem
mit echten Spielern:

```bash
cd deploy
docker compose exec app php artisan xgp:instance:create loadtest --name="Lasttest"
docker compose exec app php artisan xgp:loadtest:seed --universe=loadtest --players=2000 --password='ein-langes-passwort'
docker compose exec app php artisan xgp:loadtest:run https://play.example.com --universe=loadtest --password='ein-langes-passwort' --players=40 --requests=5000 --concurrency=40
docker compose exec app php artisan xgp:loadtest:clear --universe=loadtest
docker compose exec app php artisan xgp:instance:update loadtest --status=closed
```

- **`seed`** legt Spieler `load1@loadtest.invalid` … an, so wie die
  Registrierung es tut. Danach baut es sie aus: Gebäude, Forschung, Schiffe
  und Verteidigung in drei Stufen, je 20 Nachrichten, Allianzen zu je 50,
  Freunde und Sonden im Flug. Es verweigert ein Universum, in dem schon
  echte Spieler sind, und stellt die Anmeldung dort auf „nur mit
  Einladung“. 2.000 Spieler dauern rund zwei Minuten. Ein zweiter Aufruf
  mit höherer Zahl legt weitere dazu.
- **`run`** meldet so viele Spieler an wie `--players` (der Server lässt 20
  Anmeldungen pro Minute zu, das Werkzeug wartet dann). Danach öffnet es
  Spielseiten in der Mischung echter Spieler, meist Übersicht und Galaxie.
  Ausgabe: Seiten pro Sekunde, Antwortcodes, Median, 95 % und 99 % der
  Zeiten. Daraus folgt eine grobe Spielerzahl: Ein Spieler öffnet etwa alle
  10 Sekunden eine Seite.
- **`clear`** löscht die Testspieler wie gelöschte Konten.

So liest du das Ergebnis: Erhöhe `--concurrency` schrittweise (10, 20, 40,
80). Solange die Seiten pro Sekunde mitsteigen und der Median unter etwa
300 ms bleibt, hat der Server Luft. Steigen nur noch die Zeiten, ist die
Grenze erreicht. Nebenher zeigt `docker stats`, ob PHP (`app`) oder die
Datenbank (`db`) am Anschlag ist: mehr PHP-Prozesse oder Container
(`PHP_FPM_CHILDREN`, `APP_REPLICAS`) beziehungsweise mehr
`DB_BUFFER_POOL`. Jede Antwort außer 200 ist ein Fehler. 302 heißt
abgemeldet, 5xx steht im Log und unter /operator → *Fehler*.

Am besten läuft `run` von einem zweiten Rechner aus (Checkout mit
`composer install`), sonst zählt seine eigene Last mit. Die Spielzeit
(`xgp:tick`) läuft währenddessen weiter, wie im echten Betrieb.

## Häufige Fehler

| Meldung | Ursache und Abhilfe |
|---|---|
| `set XGP_DOMAIN in deploy/.env` (oder ein anderer Schlüssel) | Pflichtwert fehlt in `deploy/.env` |
| Caddy bekommt kein Zertifikat | DNS zeigt nicht auf den Server oder Port 80 ist zu; `docker compose logs web` |
| `The new app containers did not … render the start page` | Das neue Release startet nicht; die alte Version läuft weiter. Die Ursache steht in den Zeilen darüber (Log des Containers). |
| `The database holds tables but no game` | `xgp:install` installiert nur in eine leere Datenbank |
| Spieler werden nach einem Update abgemeldet | `APP_KEY` wurde geändert; nie neu erzeugen |
