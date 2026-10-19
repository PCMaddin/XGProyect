# Betrieb mit mehreren Universen

Dieses Handbuch beschreibt, wie ein XG-Proyect-Server mehrere Universen
betreibt: jedes in einer eigenen Datenbank, mit Lobby, monatlichem Start und
dem Kauf von Dunkler Materie per Karte (Stripe) oder WAX.

Ein Server ohne eingetragene Universen läuft wie bisher als ein einziges
Spiel. Alles hier ist also optional.

## Überblick

- **Universum:** ein vollständiges Spiel in einer eigenen Datenbank, mit
  eigenen Konten, Optionen, Sitzungen, eigenem Cache, eigenem Backup-Ordner
  und eigener Fehler-Logdatei. Konten gelten nur in ihrem Universum.
- **Basisdatenbank:** die Datenbank aus `config/xgp-db-config.php`. Sie
  enthält die Liste der Universen (`xgp_instances`) und die eingegangenen
  WAX-Überweisungen (`xgp_wax_transfers`). Sie kann selbst eines der
  Universen sein.
- **Lobby** unter `/universes`: zeigt alle Universen, neueste zuerst, mit
  Modus, Startdatum, Spielerzahl und Zahlungsarten. Die Wahl wird im Cookie
  `xgp_universe` gespeichert; ohne Wahl führt jede Seite zur Lobby.
- **Betreiberbereich** unter `/operator`: die Universen im Browser verwalten,
  mit eigenen Betreiberkonten und immer mit Zwei-Faktor-Anmeldung (siehe
  *Betreiberbereich im Browser*).
- **Modus:** `pay2win` (Dunkle Materie kann gekauft werden) oder
  `play2win` (nichts zu kaufen; Dunkle Materie nur aus Expeditionen und vom
  Admin).

## Voraussetzungen

- MySQL oder MariaDB. Mit SQLite gibt es keine Universen.
- Der Datenbank-Benutzer des Spiels darf Datenbanken anlegen, mindestens
  nach dem Muster der Universen, zum Beispiel:

  ```sql
  GRANT ALL PRIVILEGES ON `xgp\_uni\_%`.* TO 'xgp'@'localhost';
  ```

- Mit dem Docker-Stack aus `deploy/` ist alles Folgende schon eingerichtet
  (Datenbank-Rechte, Scheduler, Redis), siehe
  [deployment.md](deployment.md); Befehle dort mit
  `docker compose exec app php artisan …`.
- Der Scheduler läuft per Cron. Er hält die Spielzeit aller Universen am
  Laufen (`xgp:tick`) und startet den Monatsstart und die WAX-Gutschrift:

  ```
  * * * * * cd /pfad/zum/spiel && php artisan schedule:run >> /dev/null 2>&1
  ```

## Erste Einrichtung

1. Das Spiel wie gewohnt mit dem Installer einrichten.
2. Optional das installierte Spiel als erstes Universum übernehmen:

   ```bash
   php artisan xgp:instance:adopt classic --name="Classic" --mode=play2win
   ```

3. Weitere Universen anlegen:

   ```bash
   php artisan xgp:instance:create nov --name="November" --mode=pay2win \
     --payments=stripe,wax --bots=20 \
     --admin-name=Admin --admin-email=admin@example.com --admin-password='…' \
     --option=game_speed=5000 --option=fleet_speed=5000
   ```

Sobald ein Universum eingetragen ist, zeigt der Server die Lobby.

## Befehle

| Befehl | Was er tut |
|---|---|
| `xgp:instance:create {slug}` | legt ein Universum in einer eigenen Datenbank an (siehe unten) |
| `xgp:instance:adopt {slug}` | trägt das schon installierte Spiel als Universum ein |
| `xgp:instance:list` | zeigt alle Universen mit Datenbank, Modus, Status und Start |
| `xgp:instance:update {slug}` | ändert Name, Modus, Status oder Zahlungsarten |
| `xgp:instance:migrate {slug?}` | spielt die Update-Migrationen in einem oder allen Universen ein |
| `xgp:instance:monthly` | öffnet das Universum des Monats (läuft per Scheduler) |
| `xgp:instance:close-ended` | schließt Universen, deren Saison vorbei ist (läuft per Scheduler alle fünf Minuten) |
| `xgp:wax:check` | schreibt WAX-Zahlungen gut (läuft per Scheduler jede Minute) |
| `xgp:tick` | berechnet in jedem offenen Universum Flotten, Umzüge, Auktionen, Statistik, Aufräumen, Backup und Bots (läuft per Scheduler jede Minute) |

`php artisan <befehl> --help` zeigt alle Optionen.

Solange der Tick läuft (sein letzter Lauf ist jünger als fünf Minuten,
Option `tick_last_run`), berechnen Seitenaufrufe nur noch, was der Spieler
sofort sieht: fällige Flotten, Umzüge und Auktionen. Statistik, Aufräumen,
Backup und Bots macht der Scheduler. Fällt der Cron aus, übernehmen die
Seitenaufrufe nach fünf Minuten wieder alles.

### `xgp:instance:create`

| Option | Standard | Bedeutung |
|---|---|---|
| `slug` | – | Kurzname: Kleinbuchstaben, Ziffern, Bindestrich, bis 32 Zeichen. Steht im Cookie, im Datenbanknamen und in Mail-Links. |
| `--name` | der Slug | Name in der Lobby und als Spielname |
| `--mode` | `play2win` | `pay2win` oder `play2win` |
| `--payments` | `stripe` | Zahlungsarten in Pay2Win: `stripe`, `wax` oder `stripe,wax` |
| `--bots` | `0` | KI-Gegner zum Start, über alle Galaxien verteilt |
| `--ends` | – | Saisonende: ein Datum (`2026-12-01`) oder eine Dauer (`"+1 month"`); ohne läuft das Universum unbegrenzt |
| `--database` | `xgp_uni_<slug>` | Datenbank des Universums |
| `--admin-name`, `--admin-email`, `--admin-password` | – | erster Administrator; fehlt etwas, fragt der Befehl nach |
| `--option=name=wert` | – | weitere Spieloptionen, mehrfach möglich (`game_speed=5000`) |

Der Befehl legt die Datenbank an und richtet sie wie der Installer ein:
Tabellen, Update-Migrationen als gelaufen markiert, Changelog, Sprachen,
Optionen und den Administrator mit Heimatplanet auf 1:1:1. Sprache und
Zeitzone übernimmt er aus dem Basisspiel, wenn `--option` sie nicht setzt.

Er bricht ab, ohne etwas zu verändern, wenn der Slug schon vergeben ist, die
Datenbank einem anderen Universum gehört oder schon ein Spiel enthält. Bricht
er mitten in der Einrichtung ab, bleibt die halb eingerichtete Datenbank
liegen; dann die Datenbank von Hand löschen und den Befehl wiederholen.

### `xgp:instance:update`

```bash
php artisan xgp:instance:update nov --name="November 2026"
php artisan xgp:instance:update nov --mode=pay2win --payments=stripe,wax
php artisan xgp:instance:update nov --payments=none
php artisan xgp:instance:update nov --status=closed
php artisan xgp:instance:update nov --ends="2026-12-01 00:00"
php artisan xgp:instance:update nov --ends=none
```

Nach dem Saisonende kommt niemand mehr hinein; `xgp:instance:close-ended`
schließt das Universum dann auch in der Liste. Beim Schließen wird die
Rangliste eingefroren: Die Top 10 jeder Wertung (ohne Bots und Admins)
stehen danach in der Basisdatenbank (`xgp_season_results`) und in der Lobby
als Ruhmeshalle. Lobby, Startseite und die
Übersicht im Spiel zeigen das Saisonende an.

Ein geschlossenes Universum steht in der Lobby als „Beendet“; niemand kann
es mehr betreten. Die Daten bleiben erhalten.

## Betreiberbereich im Browser

Unter `/operator` kann man alles erledigen, was die `xgp:instance`-Befehle
tun: Universen anlegen, Name, Modus, Zahlungsarten, Saisonende und Preise
ändern, ein Universum schließen und Saisonpreise im eigenen Token
auszahlen. Die Befehle bleiben daneben bestehen.

**Konten.** Betreiberkonten liegen in der Basisdatenbank
(`xgp_operators`) und haben nichts mit den Spiel- oder Admin-Konten eines
Universums zu tun. Ein Konto legst du auf dem Server an:

```bash
php artisan xgp:operator:create du@example.com
```

Der Befehl fragt nach dem Passwort (mindestens 12 Zeichen), zeigt einen
QR-Code im Terminal (oder den Schlüssel zum Abtippen) und fragt nach dem
Code aus deiner Authenticator-App. Erst dann gibt es das Konto. Danach
zeigt er die Wiederherstellungscodes einmal an; bewahre sie sicher auf.

**Anmeldung.** Immer Passwort und dann Code aus der App oder ein
Wiederherstellungscode. Eingerichtet wird die Zwei-Faktor-Anmeldung nur auf
der Kommandozeile, nie im Browser: Wer nur das Passwort kennt, kann keine
eigene App eintragen. Nach fünf falschen Codes muss das Passwort neu
eingegeben werden; jeder Code gilt einmal. Die Sitzung endet nach 30
Minuten ohne Klick und spätestens 8 Stunden nach der Anmeldung. Sie ist
von den Universen getrennt (eigenes Cookie `xgp_operator`).

| Befehl | Zweck |
|---|---|
| `xgp:operator:create <e-mail>` | Konto mit Zwei-Faktor-Anmeldung anlegen |
| `xgp:operator:reset <e-mail> --new-password` | neues Passwort |
| `xgp:operator:reset <e-mail> --two-factor` | neue Authenticator-App (Handy verloren); die alte gilt, bis die neue bestätigt ist |
| `xgp:operator:list` | Konten mit letzter Anmeldung |
| `xgp:operator:delete <e-mail>` | Konto löschen |

Im Browser unter *Konto* änderst du dein Passwort und erzeugst neue
Wiederherstellungscodes, beides nur mit dem aktuellen Passwort und einem
Code.

**Rechtliches.** Unter *Rechtliches* schreibst du Impressum,
Datenschutzerklärung, AGB und Widerrufsbelehrung, je Sprache ein Text in
Markdown (`# Überschrift`, `**fett**`, `[Link](https://…)`, Listen mit `-`).
HTML im Text erscheint als Text, nie als Code. Die Texte liegen in der
Basisdatenbank (`xgp_legal_pages`) und gelten für alle Universen. Für
alle sind sie unter `/legal/imprint`, `/legal/privacy`, `/legal/terms`
und `/legal/withdrawal` erreichbar, auch ohne Anmeldung und ohne
gewähltes Universum. Fehlt ein Text in der Sprache des Besuchers,
erscheint der zuerst geschriebene. Verlinkt ist eine Seite erst, wenn sie
einen Text hat: in der Fußzeile der Startseite, der Lobby und jeder
Spielseite, bei der Registrierung (AGB, Datenschutz) und im Shop (AGB,
Widerruf). Ein leer gespeicherter Text entfernt die Seite in dieser
Sprache. Jede Änderung steht im Protokoll.

**Protokoll.** Jede Anmeldung, auch fehlgeschlagene, jede Änderung an
einem Universum und an einem Rechtstext steht mit Betreiber, Adresse und
Zeit in
`xgp_operator_events`; die letzten 30 Einträge zeigt die Universenliste.

**Absichern.** In der `.env`:

| Variable | Bedeutung |
|---|---|
| `XGP_OPERATOR_IPS` | nur von diesen Adressen oder Netzen, z. B. `203.0.113.7,10.0.0.0/8`; sonst gibt es `/operator` nicht (404) |
| `XGP_OPERATOR_WEB=false` | `/operator` ganz abschalten; die Befehle gehen weiter |

Hinter einem Reverse-Proxy zählt die Adresse des Proxys, solange er nicht
in `TrustProxies` eingetragen ist. Ein gefälschter `X-Forwarded-For`-Header
hilft also nicht. Ein Universum anzulegen dauert bis zu einer Minute,
weil seine Datenbank mit allen Tabellen eingerichtet wird.

## Monatlicher Start

`xgp:instance:monthly` öffnet am Ersten jedes Monats um 00:05 Uhr ein neues
Universum, wenn `XGP_MONTHLY_ENABLED=true` gesetzt ist. Läuft er öfter, legt
er nichts doppelt an.

| Variable | Standard | Bedeutung |
|---|---|---|
| `XGP_MONTHLY_ENABLED` | `false` | Monatsstart ein |
| `XGP_MONTHLY_SLUG` | `:year-:month` | Slug, z. B. `2026-11` |
| `XGP_MONTHLY_NAME` | `Universe :year-:month` | Name in der Lobby |
| `XGP_MONTHLY_MODE` | `play2win` | Modus |
| `XGP_MONTHLY_PAYMENTS` | `stripe` | Zahlungsarten |
| `XGP_MONTHLY_OPTIONS` | – | Spieloptionen, `name=wert` durch Kommas getrennt |
| `XGP_MONTHLY_BOTS` | `0` | KI-Gegner zum Start |
| `XGP_MONTHLY_DURATION` | `1 month` | Saisonlänge; leer: kein Ende |
| `XGP_MONTHLY_KEEP_OPEN` | `0` | lässt nur die neuesten N Universen offen und schließt die älteren; `0` schließt keines |
| `XGP_MONTHLY_ADMIN_NAME`, `…_EMAIL`, `…_PASSWORD` | – | Administrator jedes neuen Universums |

Platzhalter in Slug und Name: `:year`, `:month` und `:number` (Anzahl der
Universen bisher plus eins). Einen bestimmten Monat von Hand öffnen:

```bash
php artisan xgp:instance:monthly --date=2026-12-01
```

Das Admin-Passwort steht dafür im Klartext in der `.env`. Die `.env` darf
deshalb nur für den Server lesbar sein.

## Saisonpreise

Die Top 10 jeder Wertung (Gesamt, Gebäude, Forschung, Flotte,
Verteidigung, Militär) teilen beim Saisonende einen Topf Dunkler Materie,
gestaffelt nach Platz. Standard: Gesamt 100.000, die anderen Wertungen je
20.000; die Plätze 1–10 bekommen 30/20/15/10/7/6/4/3/3/2 % ihres Topfs.

| Variable | Bedeutung |
|---|---|
| `XGP_SEASON_REWARDS` | Topf je Wertung, z. B. `total=100000,military=20000` |
| `XGP_SEASON_SHARES` | Prozent je Platz, z. B. `30,20,15,10,7,6,4,3,3,2` (höchstens zehn, zusammen höchstens 100) |

Ein Universum behält den Plan, mit dem es angelegt wurde. Für ein einzelnes
Universum ändern:

```bash
php artisan xgp:instance:update nov --rewards=total=50000,military=10000 --shares=50,30,20
```

Die Lobby zeigt die Preise offener Universen, die Ruhmeshalle den Preis
jedes Platzes.

Ausgezahlt wird an das WAX-Konto, das ein Spieler unter `/account/wax`
mit seinem Spielkonto verknüpft hat: Er überweist eine Kleinstmenge WAX mit
dem angezeigten Memo `xgp-link:<code>` an das Shop-Konto. Das setzt
`XGP_WAX_ACCOUNT` und den Cron voraus. In einem späteren Pay2Win-Universum
sieht er auf derselben Seite seine Preise und löst sie dort als Dunkle
Materie ein, jeden einmal. Wer bis zum Saisonende nicht verknüpft hat,
bekommt seinen Preis nicht automatisch; der Admin kann ihn in
`xgp_season_results` nachtragen (Spalte `wax_account`).

### Preise im eigenen Token auszahlen

Gibt es einen eigenen Token (siehe *Dunkle Materie kaufen → Eigener
Token*), kannst du die Preise eines beendeten Universums stattdessen als
Token auszahlen, zum festen Kurs abgerundet:

```bash
php artisan xgp:season:payout nov
```

Der Befehl reserviert alle noch nicht eingelösten Preise verknüpfter
WAX-Konten (sie lassen sich dann nicht mehr als Dunkle Materie einlösen)
und gibt eine Tabelle sowie die fertigen `transfer`-Aktionen als JSON aus.
Die unterschreibst du **in deiner Wallet** mit dem Shop-Konto, zum
Beispiel als eine Transaktion mit mehreren Aktionen oder mit
`cleos push actions`. Der Server bekommt nie einen Schlüssel.
`xgp:wax:check` erkennt jede Überweisung am Memo `xgp-prize:<nummer>` und
markiert den Preis als ausgezahlt (Admin-Liste: „Preis ausgezahlt“). Ein
erneuter Aufruf listet nur noch die offenen. Willst du doch nicht
auszahlen: `xgp:season:payout nov --release` gibt die reservierten Preise
zum Einlösen frei.

## Dunkle Materie kaufen

Der Shop ist nur in Pay2Win-Universen offen und nur mit einer Zahlungsart,
die das Universum erlaubt **und** die auf dem Server eingerichtet ist.
Play2Win zeigt nie einen Kauf-Button.

Die Zahlungsarten eines Universums legst du mit `--payments` fest oder im
Admin unter *Premium-Einstellungen*. Dort steht auch, was in der `.env`
noch fehlt. Die Pakete stehen in `config/premium.php`, mit Preisen in Cent;
ein geänderter Preis sollte eine neue Paket-ID bekommen.

### Stripe (Karte)

1. Im Stripe-Dashboard unter *Entwickler → API-Schlüssel* den geheimen
   Schlüssel als `STRIPE_SECRET` eintragen (`sk_test_…` zum Testen,
   `sk_live_…` für echte Zahlungen).
2. Unter *Entwickler → Webhooks* einen Endpunkt
   `https://<deine-domain>/stripe/webhook` mit diesen Ereignissen anlegen:
   `checkout.session.completed`, `checkout.session.expired`,
   `checkout.session.async_payment_succeeded`,
   `checkout.session.async_payment_failed`, `charge.refunded`,
   `charge.dispute.created`.
   Sein Signatur-Schlüssel (`whsec_…`) kommt als `STRIPE_WEBHOOK_SECRET` in
   die `.env`. Ein Endpunkt dient allen Universen.
3. Optional `STRIPE_CURRENCY` (Standard `eur`).

Gutgeschrieben wird automatisch per Webhook. Eine volle Erstattung zieht die
Dunkle Materie wieder ab, soweit der Spieler sie noch hat; Teilerstattungen
regelst du von Hand. Eine Rückbuchung der Bank (Chargeback) zieht die Dunkle
Materie ebenso ab; die Bestellung steht dann auf „Rückbuchung“. Gewinnst
du den Streitfall, schreibst du sie von Hand wieder gut.

### WAX

1. Ein WAX-Konto für den Shop anlegen und als `XGP_WAX_ACCOUNT` eintragen.
   Der Server braucht nur den Kontonamen, nie einen privaten Schlüssel:
   leg die Schlüssel nicht auf den Server.
2. Optional `XGP_WAX_MARKUP` (Aufschlag in Prozent gegen Kursschwankungen)
   und `XGP_WAX_API` (Hyperion-Server, Standard `https://wax.eosusa.io`).
   `XGP_WAX_VERIFY_API` ist ein zweiter Hyperion-Server **eines anderen
   Betreibers** (Standard `https://api.waxsweden.org`): Jede Überweisung, die
   etwas gutschreibt, verknüpft oder auszahlt, muss er bestätigen. Ein
   einzelner Server, der falsche Daten liefert oder übernommen wurde, kann so
   keine Zahlungen erfinden. Leer schaltet die Prüfung ab (nicht empfohlen).
   `XGP_WAX_MAX_RATE_JUMP` (Standard 50): Ein WAX-Kurs, der mehr als so viele
   Prozent vom letzten abweicht, wird nicht geglaubt; bis er sich beruhigt
   (oder ein Tag ohne Kurs vergangen ist), gibt es kein WAX-Angebot.
3. Cron für den Scheduler einrichten (siehe Voraussetzungen).

Der Preis in WAX wird aus dem Euro-Preis zum aktuellen Kurs (CoinGecko)
berechnet und auf 0,01 WAX aufgerundet; das Angebot gilt 30 Minuten. Der
Spieler überweist den Betrag mit dem angezeigten Memo
(`xgp:<universum>:<bestellung>`).

`xgp:wax:check` schreibt jede Überweisung einmal gut, sobald ihr Block
endgültig ist. Es zählt nur echtes WAX vom Vertrag `eosio.token`. Was nicht
passt, steht im Admin unter *Bestellungen anzeigen → WAX-Eingänge*:

| Ergebnis | Bedeutung | Was tun |
|---|---|---|
| gutgeschrieben | alles in Ordnung | nichts |
| zu spät | nach Ablauf des Angebots bezahlt | von Hand gutschreiben oder zurücksenden |
| zu wenig | Betrag unter dem Angebot | Rest anfordern, von Hand gutschreiben oder zurücksenden |
| Bestellung nicht offen | z. B. doppelt bezahlt | zurücksenden |
| unbekanntes Memo | kein Universum oder keine Bestellung erkannt | Spieler fragen oder zurücksenden |
| Spieler gelöscht | Konto existiert nicht mehr | zurücksenden |
| vom zweiten Server nicht bestätigt | der zweite Hyperion-Server kennt die Überweisung anders oder nach 30 Minuten gar nicht | auf einem Block-Explorer prüfen; echt: von Hand gutschreiben; sonst ist der erste Server verdächtig |
| WAX-Konto verknüpft | Verknüpfung per `xgp-link:`-Memo | nichts |
| Preis ausgezahlt | deine Token-Auszahlung mit `xgp-prize:`-Memo | nichts |

Von Hand gutschreiben: im Admin unter *Benutzer → Premium* des Spielers.

### Eigener Token

Statt oder neben WAX kann der Shop einen eigenen Token annehmen, den du auf
der WAX-Chain herausgibst und der an der Börse (z. B. Alcor) gegen WAX
gehandelt wird. Im Shop kauft er immer gleich viel Dunkle Materie; was er
am Markt wert ist, entscheidet der Handel. Spieler, die Preise in Token
bekommen, verkaufen sie nicht billiger, als der Shop sie annimmt: wer weiter
spielt, bekommt dafür Dunkle Materie.

1. Token herausgeben (eigener Vertrag, z. B. mit dem Standard
   `eosio.token`-Code) und `XGP_TOKEN_CONTRACT`, `XGP_TOKEN_SYMBOL` und
   `XGP_TOKEN_DECIMALS` (Standard 4) eintragen. `XGP_WAX_ACCOUNT` ist das
   Konto, an das gezahlt wird.
2. `XGP_TOKEN_DARK_MATTER`: Dunkle Materie für einen Token (Standard 100).
3. Optional `XGP_TOKEN_MARKET_URL` für den Marktpreis (Standard: Alcor,
   `:id` wird zu `symbol-vertrag`). Der Shop zeigt ihn zum Vergleich in WAX.
4. Im Universum die Zahlungsart `token` erlauben
   (`--payments=stripe,wax,token` oder im Admin).

Eine Token-Bestellung gilt 24 Stunden, der Preis ist fest. Es zählt nur der
Token genau dieses Vertrags mit genau diesem Symbol und dieser
Nachkommazahl; ein gleichnamiger Token eines anderen Vertrags zählt nicht,
WAX für eine Token-Bestellung auch nicht (und umgekehrt). Die Ergebnisse
stehen wie bei WAX in der Admin-Liste.

### Widerrufsrecht und Bestätigung

Dunkle Materie ist ein digitaler Inhalt. Der Spieler kauft erst, wenn er
im Shop ankreuzt, dass sie sofort gutgeschrieben werden soll und er damit
sein Widerrufsrecht verliert. Ohne Haken gibt es keine Bestellung, auch
nicht mit einer selbst gebauten Anfrage. Der Zeitpunkt steht an der
Bestellung (`withdrawal_waived_at`).

Nach der Gutschrift bekommt der Spieler eine Bestätigung per Mail, in der
Sprache des Universums: Bestellnummer, Datum, Menge, Preis, Zahlungsart
und die Erklärung zum Widerrufsrecht. Das ist die Bestätigung auf einem
dauerhaften Datenträger, die das Gesetz verlangt. Absender ist die
Admin-Adresse des Universums. Ist der Mailserver nicht erreichbar, bleibt
die Gutschrift bestehen; der Fehler steht im Log.

### Vor dem ersten echten Verkauf

Impressum, AGB, Datenschutzerklärung und Widerrufsbelehrung unter
*Rechtliches* im Betreiberbereich eintragen (siehe oben) und anwaltlich
prüfen lassen. Einnahmen in WAX verbuchst du mit dem Euro-Wert am
Zahlungstag; Bestellung und Admin-Liste zeigen Euro-Preis und WAX-Betrag.

## Updates

Mit dem Docker-Stack: `deploy/deploy.sh` (siehe [deployment.md](deployment.md)).
Sonst nach jedem Code-Update:

```bash
composer install --no-dev
php artisan config:cache
php artisan xgp:instance:migrate
```

`xgp:instance:migrate` spielt die Update-Migrationen
(`database/migrations/400`) in allen Universen ein und die Migrationen der
Basisdatenbank (`database/migrations/central`). Ohne Universen genügt wie
bisher `php artisan migrate --path=database/migrations/400`.

## Backups und Logs

- **Backups:** Das automatische Backup im Admin sichert die Datenbank des
  aktiven Universums in einen eigenen Ordner unter
  `storage/app/backups/<APP_NAME>_<slug>/`. Die Basisdatenbank sicherst du
  zusätzlich selbst, z. B. per `mysqldump`, weil dort Universen-Liste und
  WAX-Eingänge liegen.
- **Logs:** Jedes Universum schreibt nach
  `storage/logs/xgproyect-<slug>.log`; die Fehlerliste im Admin zeigt nur
  diese Datei. Lobby, Befehle und alles ohne Universum schreiben nach
  `storage/logs/xgproyect.log`.

## Mail-Links

Mails eines Universums (Passwort vergessen, Willkommen, Rundmails) hängen
`?universe=<slug>` an ihre Links. So landet der Spieler auch auf einem
anderen Gerät im richtigen Universum; der Link merkt sich die Wahl wie die
Lobby.

## E-Mail-Bestätigung

Wer sich auf der Startseite registriert, bekommt statt der
Willkommens-E-Mail einen Link, der die Adresse bestätigt. Der Link ist
signiert und gilt 7 Tage, nur für diese Adresse. Er funktioniert ohne
Anmeldung und auf jedem Gerät.

Bis zur Bestätigung:
- erinnert jede Spielseite daran, mit dem Löschdatum und einem Knopf zum
  erneuten Senden (höchstens 3 pro Stunde);
- verkauft der Shop nichts.

Ein Konto, das nach 7 Tagen nicht bestätigt ist, wird beim nächsten
Aufräumen gelöscht. Das erschwert Wegwerf- und Zweitkonten.

Als bestätigt gelten:
- Admins, Bots und Spieler, die der Admin anlegt;
- eine Adresse, die der Admin ändert;
- ein Konto nach einem abgeschlossenen Passwort-Zurücksetzen (der Link kam
  ja per E-Mail);
- alle Konten, die es beim Update schon gab.

Abschalten unter Admin → Registrierung → *E-Mail-Adresse bestätigen
lassen* (Option `reg_verify_email`). Ohne die Option gilt jede neue Adresse
sofort als bestätigt, und es geht wieder die Willkommens-E-Mail raus.
Schaltest du sie später ein, wird deshalb niemand gelöscht. Voraussetzung
ist ein funktionierender Mailversand: Ohne ihn verlieren neue Spieler
nach einer Woche ihr Konto.

## Datenschutz

**Für Spieler.** In den Einstellungen unter *Deine Daten*
(`/account/privacy`) lädt ein Spieler alles herunter, was das Universum
über sein Konto speichert. Das ist eine JSON-Datei für Auskunft und
Datenübertragbarkeit. Ebenso löscht er dort sein Konto sofort. Beides
verlangt das Passwort und mit Zwei-Faktor-Anmeldung den Code. Fünf
Fehlversuche sperren für 15 Minuten. Admin-Konten können sich nicht selbst
löschen, weil ihre Sperren an ihnen hängen. Das verzögerte Löschen nach
7 Tagen in den Einstellungen gibt es weiterhin.

**Die Datei** enthält Konto, Planeten mit Gebäuden, Schiffen und
Verteidigung, Forschung, Flotten, Nachrichten, Freunde, Notizen, Allianz,
Käufe, Gegenstände, Gebote, Import/Export und die Anmeldungen mit
IP-Adresse und Browser. Dazu kommen Sitzungen, Aktivität, die
WAX-Verknüpfung und die Saisonplätze. Nicht enthalten sind Passwort,
Zwei-Faktor-Schlüssel, Wiederherstellungscodes und die Funde der
Betrugserkennung.

**Löschen** entfernt alles, was das Konto nennt. Das gilt auch für den
Admin und das automatische Löschen inaktiver Konten, die denselben Weg
nehmen (`AccountEraser`):
- Planeten, Nachrichten, Freunde, Notizen, Gegenstände, Gebote;
- Anmeldungen, Sitzungen, Aktivität, Spionage-Protokoll;
- Verdachtsfälle, Sperren, Angriffsverbände;
- Tokens zum Zurücksetzen des Passworts und die WAX-Verknüpfung.

Ohne Bezug zur Person bleiben bezahlte Bestellungen (Aufbewahrungspflicht)
und die Ruhmeshalle, dort mit `-` statt Namen. Ebenso bleiben
Rohstoffübergaben bis zur regulären Löschung.

**Fristen für die Datenschutzerklärung** (Stand des Codes):

| Daten | gelöscht nach |
|---|---|
| Anmeldungen mit IP und Browser, Rohstoffübergaben, Aktivität | 60 Tagen (`XGP_CHEATS_KEEP_DAYS`) |
| Nachrichten und Kampfberichte | 7 Tagen |
| Konto, das 7 Tage im Löschmodus war | sofort danach |
| Konto ohne Anmeldung (außer Admins und Bots) | 1 Monat |
| Konto mit unbestätigter E-Mail-Adresse | 7 Tagen nach der Registrierung |
| Sitzung | 120 Minuten ohne Aktivität (`SESSION_LIFETIME`) |
| Log-Dateien | 14 Tagen mit `LOG_CHANNEL=daily` (Standard der Beispiel-`.env`); mit `stack` wächst eine einzige Datei ohne Ende |
| letzte IP und Browser am Konto | mit dem Konto |
| bezahlte Bestellungen | nie automatisch (gesetzliche Aufbewahrung, z. B. 10 Jahre) |

Die Admin-Backups (Admin → Backup) enthalten alle Daten. Ihre
Aufbewahrung stellst du dort ein; nenne sie in der Datenschutzerklärung.

## Betrugserkennung

Weil sich die Top 10 lohnen, lohnen sich auch Zweitkonten und Pushing. Das
Spiel protokolliert deshalb je Universum:

- Logins mit IP, Browser und einem Geräte-Cookie;
- Rohstofflieferungen an andere Spieler;
- Anfragen pro Stunde.

`xgp:cheat:scan` (stündlich) macht daraus Hinweise unter
*Admin → Information → Verdachtsfälle*. Die Schwellen stehen in
`config/cheats.php` (`XGP_CHEATS_*`).

| Hinweis | Bedeutung |
|---|---|
| gleicher Browser | zwei Konten im selben Browser (starkes Zeichen) |
| gleiche IP-Adresse | kann auch ein Haushalt oder ein Handynetz sein |
| schickt Stärkerem Rohstoffe / bekommt Rohstoffe | netto über 100.000 an einen Stärkeren; stärker gewertet, wenn die beiden auch Browser oder IP teilen |
| fast rund um die Uhr aktiv | an drei Tagen der letzten Woche je mindestens 20 Stunden aktiv |
| Klicktempo wie ein Skript | über 1.200 Anfragen in einer Stunde |

Die Hinweise sind keine Beweise; du entscheidest je Hinweis: „in Ordnung“
oder „ausgeschlossen“. Ausgeschlossene Spieler fehlen beim Saisonende in
allen Wertungen und bekommen keinen Preis. Prüfe die Liste vor jedem
Saisonende; Spieler in den Top 10 sind markiert. Protokolle älter als 60
Tage werden gelöscht.

## Zwei-Faktor-Anmeldung

Der Admin-Bereich verlangt eine Zwei-Faktor-Anmeldung mit einer
Authenticator-App (TOTP). Ein Admin ohne sie wird nach dem Login zur
Einrichtung unter `/account/security` geschickt. Spieler können sie dort
freiwillig einschalten. Da die Konten je Universum getrennt sind, richtet
man sie in jedem Universum einzeln ein.

- Das Geheimnis wird mit dem `APP_KEY` verschlüsselt gespeichert. Wer den
  `APP_KEY` ändert, macht alle eingerichteten Apps ungültig.
- Hat ein Spieler Handy und Wiederherstellungscodes verloren, schaltet ein
  Admin sie in der Datenbank ab: in der Tabelle `xgp_users` des
  Universums die Spalten `two_factor_secret`,
  `two_factor_recovery_codes`, `two_factor_confirmed_at` und
  `two_factor_last_step` auf `NULL` setzen.
- Nur für lokale Arbeit: `XGP_ADMIN_TWO_FACTOR=false` hebt die Pflicht auf.

Für den Betreiberbereich `/operator` gilt die Zwei-Faktor-Anmeldung immer
und lässt sich nicht abschalten. Sie wird beim Anlegen des Kontos auf der
Kommandozeile eingerichtet (siehe *Betreiberbereich im Browser*). Verlorenes
Handy: `php artisan xgp:operator:reset <e-mail> --two-factor`.

## Sicherheit im Betrieb

Mit echtem Geld im Spiel lohnt sich der Angriff. Der Code schützt die
Geldwege so:

- **Zahlungen:** Stripe nur mit gültiger Signatur. WAX und Token erst, wenn
  der Block unumkehrbar ist und ein zweiter Server die Überweisung
  bestätigt. Jede Zahlung zählt einmal. Rückbuchungen nehmen die Dunkle
  Materie zurück.
- **Ausgeben:** Dunkle Materie wird nur abgebucht, wenn sie im selben
  Moment noch da ist; zwei gleichzeitige Käufe zahlen nicht aus demselben
  Guthaben.
- **Konten:** Begrenzte Versuche bei Login, Admin-Login, Registrierung und
  „Passwort vergessen“; Zwei-Faktor-Code nach fünf Fehlern 15 Minuten
  gesperrt. Der Link in der Reset-Mail zeigt immer auf `APP_URL`.
- **WAX-Verknüpfung:** Ein Code gilt einmal. Wer ein öffentlich sichtbares
  Memo noch einmal sendet, übernimmt keine fremde Verknüpfung.
- **Insider:** Setzt ein Admin eines Universums die Dunkle Materie eines
  Spielers, steht das mit altem und neuem Wert im Protokoll unter
  `/operator`, wo der Admin es nicht löschen kann.
- **Seiten:** Keine fremde Seite darf das Spiel einbetten (Klick-Fallen auf
  Kauf-Buttons); über HTTPS gilt HSTS.
- **Eingaben:** Namen, Texte und Nachrichten erscheinen als Text, nie als
  Code, auch in Tooltips. Admins können keine Namen mit `< > " ' & \`
  mehr setzen. Der Sprach-Editor im Admin ist geschlossen, sobald
  Universen registriert sind: Die Dateien in `lang/` gelten für alle
  Universen, du bearbeitest sie auf dem Server.
- **Spielstand:** Gleichzeitige Seitenaufrufe (mehrere Tabs, Skripte)
  verdoppeln nichts mehr. Jede Flotte wird einmal verarbeitet, zwei Flotten
  auf dasselbe Ziel nacheinander. Rohstoffe und Schiffe werden nur
  abgezogen, wenn sie im selben Moment noch da sind. Die Produktion schreibt
  nur Zuwächse.

Was du auf dem Server tun musst:

- `APP_URL` auf die öffentliche HTTPS-Adresse setzen (sonst zeigen
  Reset-Links ins Leere), `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`.
- Das Webverzeichnis auf `public/` zeigen lassen, nie auf das Projekt.
- `composer install --no-dev` (keine Debug-Werkzeuge auf dem Server).
- Den Shop-Schlüssel für WAX nie auf den Server legen.
- Das Protokoll unter `/operator` regelmäßig ansehen, besonders
  „Admin hat Dunkle Materie gesetzt“ und nicht bestätigte WAX-Eingänge.

## Viele Spieler

Ein Universum fasst 22.455 neue Spieler (Heimatplaneten auf den Positionen
4 bis 12 jedes Systems). Ist es voll, sagt die Registrierung das; öffne
dann ein weiteres Universum. Mit 1 Mio. Spielern in einem (absichtlich
überfüllten) Universum getestet: jede Spielerseite unter 130 ms.

- **Statistik:** Der Neuaufbau aller Punkte und Ränge braucht bei
  1 Mio. Spielern rund zwei Minuten. Stelle `stat_update_time`
  (Admin → Server) für große Universen auf 15 Minuten oder mehr. Der
  Scheduler (`xgp:tick`) baut sie im Hintergrund; ohne Cron übernimmt
  ein Seitenaufruf, unter php-fpm erst nach seiner Antwort.
- **Webserver:** php-fpm, nicht `php artisan serve` (dort wartet der
  Aufruf, der die Statistik übernimmt, bis sie fertig ist).
- **Sitzungen und Cache auf Redis:** Ab einigen tausend gleichzeitigen
  Spielern oder mit mehreren App-Servern:

  ```
  CACHE_DRIVER=redis
  SESSION_DRIVER=redis
  ```

  Alle Universen teilen sich den Redis-Server. Jedes bekommt eigene
  Schlüssel (`<CACHE_PREFIX>_<slug>:…`), eine Sitzung aus einem Universum
  gilt in keinem anderen. Der Cache liegt in Redis-Datenbank 1, die
  Sitzungen in Datenbank 2 (`REDIS_SESSION_DB`), damit `cache:clear` niemanden
  abmeldet. Am besten bekommen die Sitzungen einen eigenen Redis ohne
  Verdrängung (`maxmemory-policy noeviction`, mit AOF) über
  `REDIS_SESSION_HOST`/`REDIS_SESSION_PORT`, der Cache einen mit
  `allkeys-lru`. Platzbedarf der Sitzungen: etwa 1 KB je angemeldetem
  Spieler. Beim Umstellen von `database` auf `redis` melden sich alle
  einmal neu an. `cache:clear` leert den Cache aller Universen.
- **Cache in Dateien:** Ohne Redis bekommt jedes Universum einen eigenen
  Ordner (`storage/framework/cache/data_<slug>`).
- **Datenbank:** `innodb_buffer_pool_size` etwa so groß wie die Daten
  (1 Mio. Spieler ≈ 2,5 GB).
- **Betrugserkennung:** Browser und Adressen mit mehr als
  `XGP_CHEATS_SHARED_MAX_ACCOUNTS` (Standard 20) Konten gelten als
  öffentliches Netz (Mobilfunk, Uni) und nicht als Hinweis.
- **Admin:** Listen von Spielern und Allianzen zeigen die ersten 500 nach
  Namen; das Suchfeld darüber findet die anderen nach dem Namensanfang.

## Häufige Fehler

| Meldung | Ursache | Lösung |
|---|---|---|
| `Universes need a MySQL or MariaDB server.` | Spiel läuft mit SQLite | auf MySQL oder MariaDB umstellen |
| `Access denied … to database 'xgp_uni_…'` | Datenbank-Benutzer darf keine Datenbanken anlegen | Rechte wie unter Voraussetzungen vergeben |
| `The universe … exists already.` | Slug vergeben | anderen Slug wählen |
| `The database … holds a game already.` | Datenbank enthält Tabellen | andere Datenbank wählen oder die alte von Hand löschen |
| Spieler landen immer in der Lobby | Universum geschlossen oder Cookie fehlt | `xgp:instance:list` prüfen, ggf. `--status=open` |
| Kein Kauf-Button | Play2Win, keine erlaubte oder keine eingerichtete Zahlungsart | Admin → Premium-Einstellungen zeigt, was fehlt |
| `/operator` zeigt 404 | `XGP_OPERATOR_IPS` lässt deine Adresse nicht zu, oder `XGP_OPERATOR_WEB=false` | Adresse eintragen oder Variable entfernen |
| Betreiber-Login schlägt immer fehl | Konto ohne Zwei-Faktor-Anmeldung oder falsches Passwort | `xgp:operator:list`, dann `xgp:operator:reset` |
| WAX-Zahlungen kommen nicht an | Cron läuft nicht, `XGP_WAX_ACCOUNT` fehlt oder Hyperion-Server nicht erreichbar | `php artisan xgp:wax:check` von Hand ausführen und Ausgabe lesen |
