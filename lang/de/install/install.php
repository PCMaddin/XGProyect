<?php

declare(strict_types=1);

return [
    // sidebar
    'sidebar_heading' => 'Installation',
    'steps' => 'Schritte',
    'requirements' => 'Voraussetzungen',
    'database' => 'Datenbankeinstellungen',
    'tables' => 'Tabellen anlegen',
    'admin' => 'Administratordaten',
    'final' => 'Letzte Schritte',
    'finish' => 'Fertig',

    // next step notices
    'index_notice' => 'Im nächsten Schritt prüfen wir, ob du XGP installieren kannst',
    'requirements_notice' => 'Im nächsten Schritt beginnen wir mit der Installation von XGP 🎉',
    'database_notice' => 'Im nächsten Schritt legen wir die Tabellen an und füllen sie mit Daten. <strong>Achtung!</strong> Der nächste Schritt leert die Datenbank!',
    'tables_notice' => 'Im nächsten Schritt legen wir das Administratorkonto an.',
    'admin_notice' => 'Im nächsten Schritt führen wir einige abschließende Prüfungen durch.',
    'final_notice' => 'Alles erledigt! 🎉 Weiter zum Admin-Bereich',

    // home
    'welcome' => 'Willkommen bei XG Proyect!',
    'introduction' => '<strong>XG Proyect</strong>, auch XGP genannt, ist der ultimative OGame-Klon. <strong>XGP 4</strong> ist das neueste und zuverlässigste Paket, das je entwickelt wurde. Wie die Vorgängerversionen wird XG Proyect vom Team unterstützt, das früher als Xtreme-gameZ bekannt war, was erstklassige Betreuung und stabile Versionen sicherstellt.<br><br>XG Proyect 4 steht für ständiges Wachstum, Stabilität, Flexibilität, Dynamik, Qualität und das Vertrauen der Nutzer. Unser Ziel ist, dass XG Proyect deine Erwartungen übertrifft.<br><br>Das Installationssystem führt dich nahtlos durch die Installation. Bei Fragen oder Problemen besuche gern unsere <a href="https://www.xgproyect.org/" target="_blank"><em>Support- und Entwickler-Community auf GitHub</em></a>.',
    'license' => 'Lizenz',
    'accept' => 'Alles klar, verstanden!',

    // requirements
    'requirements_details' => 'Das sind die Mindestvoraussetzungen für XGP.<br><br>Derzeit unterstützen wir nur MySQL / MariaDB, wollen aber künftig weitere Treiber unterstützen. Die Version des Datenbankservers wird geprüft, nachdem du die Verbindungsdaten eingegeben hast.',
    'php_version_check' => 'PHP-Version :version',
    'php_version_current' => 'Du hast :php',
    'php_version_need' => 'Du brauchst mindestens :version',
    'mysql_check' => 'MySQL / MariaDB :version+',
    'mysql_check_current' => 'Wird geprüft, nachdem du die Datenbankzugangsdaten eingegeben hast',
    'mysql_check_need' => 'Du brauchst MySQL / MariaDB :version oder neuer',
    'config_writable' => 'Beschreibbarer Konfigurationspfad',
    'config_writable_ok' => 'OK',
    'config_writable_need' => ':file ist nicht beschreibbar',
    'php_ext_check' => 'PHP-Erweiterungen',
    'php_ext_check_ok' => 'OK',
    'php_ext_check_need' => 'Du brauchst: :ext',
    'requirements_fail' => 'Dein System erfüllt die Voraussetzungen nicht, du kannst nicht fortfahren!',

    // database
    'database_details' => 'Hier richtest du die Verbindung zu deiner Datenbank ein.<br><br>Bitte achte genau auf deine Einstellungen, sie müssen exakt übereinstimmen.',
    'driver' => 'Treiber',
    'host' => 'Datenbank-Host',
    'port' => 'Datenbank-Port',
    'port_help' => 'Lass dieses Feld leer, außer du weißt, dass der Server einen anderen als den Standardport nutzt.',
    'db_name' => 'Datenbankname',
    'db_username' => 'Datenbank-Benutzername',
    'db_password' => 'Datenbank-Passwort',
    'prefix' => 'Tabellenpräfix',
    'prefix_help' => 'Vor allem nützlich, wenn du mehrere XGP-Installationen in derselben Datenbank betreiben willst.',
    'db_check' => 'Verbindung testen',
    'db_connect_success' => 'Verbindung erfolgreich hergestellt!',
    'db_version_fail' => 'Der Datenbankserver muss mindestens Version :version haben. Aktuelle Version: :current.',
    'db_connect_fail' => 'Die Verbindung konnte nicht hergestellt werden. Prüfe die Angaben und versuche es erneut!',

    // tables
    'tables_details' => 'Die grundlegende Datenbankstruktur wurde angelegt.<br><br><strong>Vorsicht</strong> - dieses Skript löscht alle vorhandenen Tabellen samt Datensätzen.',
    'results' => 'Ergebnisprotokoll',
    'wipe' => 'Datenbank geleert',
    'clear_cache' => 'Cache geleert',
    'prepare_config' => 'Konfiguration bereit',
    'install' => 'Basistabellen bereit',
    'create' => 'Spieltabellen bereit',
    'insert_changelog' => 'Changelog-Einträge eingefügt',
    'insert_languages' => 'Spracheinträge eingefügt',
    'insert_options' => 'Optionen eingefügt',
    'no_logs' => 'Keine Protokolle - lade die Seite neu, um die Installation erneut zu versuchen.',
    'error_install' => 'Fehler bei der Installation! Lade die Seite neu, um es erneut zu versuchen!',
    'success_install' => 'Das Spiel wurde erfolgreich installiert!',

    // admin
    'admin_details' => 'In diesem Schritt legst du dein Administratorkonto an.<br><br>Bewahre die Zugangsdaten sicher auf! Nutze nach Möglichkeit einen Passwortmanager wie <a href="https://1password.com/" target="_blank">1Password</a>, <a href="https://www.lastpass.com/" target="_blank">LastPass</a> oder den deines Browsers und wähle ein sehr sicheres Passwort.',
    'admin_email' => 'E-Mail-Adresse des Administrators',
    'admin_email_help' => 'Damit meldest du dich im Admin-Bereich an',
    'admin_username' => 'Benutzername des Administrators',
    'admin_username_help' => 'Unter diesem Namen sehen dich andere Spieler im Spiel.',
    'admin_password' => 'Passwort des Administrators',
    'admin_password_confirm' => 'Passwort des Administrators bestätigen',
    'random_password' => 'Ein sehr sicheres Zufallspasswort für mich erzeugen',
    'create_admin' => 'Administrator anlegen',
    'admin_create_success' => 'Administrator erfolgreich angelegt!',
    'admin_create_fail' => 'Der Administrator konnte nicht angelegt werden. Prüfe die Angaben und versuche es erneut!',

    // final
    'final_details' => 'In diesem Schritt führst du einige abschließende Prüfungen durch und sicherst dein Spiel weiter ab.<br><br>Zeit zum Aufräumen!',
    'final_install_not_accessible' => 'Installation NICHT erreichbar',
    'final_install_not_accessible_ok' => 'OK',
    'final_install_not_accessible_need' => 'Die Installation ist erreichbar',
    'final_config_writable' => 'Konfigurationspfad NICHT beschreibbar',
    'final_config_writable_ok' => 'OK',
    'final_config_writable_need' => ':file ist beschreibbar',
    'final_fail' => 'Du musst das Spiel als installiert markieren und sicherstellen, dass die Konfigurationsdatei nicht beschreibbar ist!',
];
