<?php

declare(strict_types=1);

return [
    'se_server_parameters' => 'Servereinstellungen',
    'se_sub_title' => 'Hier konfigurierst du alle grundlegenden Einstellungen deines Servers, darunter Universumsgeschwindigkeit, Flottenoptionen, Wirtschaftsraten und mehr.',
    // Section headings
    'se_section_identity' => 'Identität',
    'se_section_speed' => 'Geschwindigkeit & Wirtschaft',
    'se_section_access' => 'Serverzugang',
    'se_section_datetime' => 'Datum & Uhrzeit',
    'se_section_combat' => 'Kampfregeln',
    // Identity
    'se_name' => 'Name',
    'se_logo' => 'Logo',
    'se_lang' => 'Sprache',
    'se_admin_email' => 'E-Mail-Adresse des Administrators',
    'se_forum_link' => 'Forum-Link',
    'se_server_name' => 'Name deines Servers',
    'se_server_logo' => 'Das Logo deines Servers, als URL. Leer: das mitgelieferte XG-Proyect-Logo.',
    // Speed & Economy
    'se_general_speed' => 'Allgemeine Geschwindigkeit',
    'se_normal_speed' => 'Standardgeschwindigkeit: 1<br>Empfohlenes Maximum: 100',
    'se_normal_speed_resoruces' => 'Standard-Produktionsgeschwindigkeit: 1<br>Empfohlenes Maximum: 100',
    'se_normal_speed_fleett' => 'Standard-Flottengeschwindigkeit: 1<br>Empfohlenes Maximum: 100',
    'se_fleet_speed' => 'Flottengeschwindigkeit',
    'se_resources_producion_speed' => 'Produktionsgeschwindigkeit',
    // Server access
    'se_server_op_close' => 'Server aktivieren',
    'se_server_status_message' => 'Nachricht bei deaktiviertem Server',
    // Date & Time
    'se_date_time_zone' => 'Zeitzone',
    'se_date_format' => 'Kurzes Datum',
    'se_date_format_extended' => 'Ausführliches Datum',
    // Combat rules
    'se_several_parameters' => 'Weitere Einstellungen',
    'se_title_admins_protection' => 'Aktiviere den Schutz, damit Administratoren und Moderatoren weder angegriffen noch von anderen Flottenaktionen erreicht werden können.',
    'se_admin_protection' => 'Admin-Schutz',
    'se_def_cdr' => 'Verteidigung ins Trümmerfeld',
    'se_ships_cdr' => 'Schiffe ins Trümmerfeld',
    'se_def_cdr_message' => 'Lege fest, welcher Prozentsatz der zerstörten Verteidigung nach einem Angriff ins Trümmerfeld geht',
    'se_ships_cdr_message' => 'Lege fest, welcher Prozentsatz der zerstörten Schiffe nach einem Angriff ins Trümmerfeld geht',
    // Noob protection
    'se_noob_protect' => 'Neulingsschutz',
    'se_noob_protect_active' => 'Schutz aktivieren',
    'se_noob_protect2' => 'Basispunkte',
    'se_noob_protect3' => 'Multiplikator',
    'se_noob_protect_e2' => 'Punktegrenze, ab der der Schutz entfällt',
    'se_noob_protect_e3' => 'Am besten erklärt ein Beispiel, wie der Multiplikator funktioniert:<br>
    Punkte des Spielers * Multiplikator = Punkte, bis zu denen ein Spieler angegriffen werden kann.<br>
    <br>
    Bsp.:<br>
    Hat der Spieler 10 Punkte und der Multiplikator ist 5:<br>
    <br>
    10 * 5 = 50<br>
    <br>
    Spieler mit bis zu 50 Punkten können einen schwachen Spieler angreifen, Spieler mit mehr als 50 nicht.<br>
    <br>
    Welche Spieler dieser schwache Spieler angreifen kann, wird so berechnet:<br>
    <br>
    10 / 5 = 2<br>
    <br>
    Er kann also jeden Spieler mit mehr als 2 Punkten angreifen.',
    // Misc
    'se_save_parameters' => 'Änderungen speichern',
    'se_configuration_title' => 'Konfiguration',
    'se_server_planet_parameters' => 'Einstellungen für neue Planeten',
    'se_all_ok_message' => 'Änderungen erfolgreich gespeichert!',
];
