<?php

declare(strict_types=1);

return [
    // sidebar
    'sidebar_heading' => 'Installatie',
    'steps' => 'Stappen',
    'requirements' => 'Vereisten',
    'database' => 'Database-instellingen',
    'tables' => 'Tabellen aanmaken',
    'admin' => 'Beheerdergegevens',
    'final' => 'Laatste stappen',
    'finish' => 'Afronden',

    // next step notices
    'index_notice' => 'In de volgende stap controleren we of je XGP kunt installeren',
    'requirements_notice' => 'In de volgende stap beginnen we met de installatie van XGP 🎉',
    'database_notice' => 'In de volgende stap maken we de tabellen aan en vullen we ze met gegevens. <strong>Let op!</strong> De volgende stap maakt de database leeg!',
    'tables_notice' => 'In de volgende stap maken we het beheerdersaccount aan.',
    'admin_notice' => 'In de volgende stap doen we enkele laatste controles.',
    'final_notice' => 'Klaar! 🎉 Op naar het beheerpaneel',

    // home
    'welcome' => 'Welkom bij XG Proyect!',
    'introduction' => '<strong>XG Proyect</strong>, ook bekend als XGP, is de meest complete OGame-kloon die je kunt vinden. <strong>XGP 4</strong> is de nieuwste en betrouwbaarste versie ooit. Net als eerdere versies wordt XG Proyect ondersteund door het team dat vroeger Xtreme-gameZ heette, wat zorgt voor kwaliteit en stabiliteit.<br><br>XG Proyect 4 staat voor constante groei, stabiliteit, flexibiliteit, dynamiek, kwaliteit en het vertrouwen van gebruikers. We willen dat XG Proyect je verwachtingen altijd overtreft.<br><br>Het installatieprogramma leidt je soepel door de installatie. Heb je vragen of problemen, bezoek dan onze <a href="https://www.xgproyect.org/" target="_blank"><em>support- en ontwikkelcommunity op GitHub</em></a>.',
    'license' => 'Licentie',
    'accept' => 'Begrepen!',

    // requirements
    'requirements_details' => 'Dit zijn de minimale vereisten om XGP te draaien.<br><br>Op dit moment ondersteunen we alleen MySQL / MariaDB, maar we willen in de toekomst andere drivers ondersteunen. De versie van de databaseserver wordt gecontroleerd nadat je de verbindingsgegevens hebt ingevuld.',
    'php_version_check' => 'PHP-versie :version',
    'php_version_current' => 'Je hebt :php',
    'php_version_need' => 'Je hebt minstens :version nodig',
    'mysql_check' => 'MySQL / MariaDB :version+',
    'mysql_check_current' => 'Gecontroleerd nadat je de databasegegevens hebt ingevuld',
    'mysql_check_need' => 'Je hebt MySQL / MariaDB :version of nieuwer nodig',
    'config_writable' => 'Beschrijfbare configuratiemap',
    'config_writable_ok' => 'OK',
    'config_writable_need' => ':file is niet beschrijfbaar',
    'php_ext_check' => 'PHP-extensies',
    'php_ext_check_ok' => 'OK',
    'php_ext_check_need' => 'Je hebt nodig: :ext',
    'requirements_fail' => 'Je systeem voldoet niet aan de vereisten, je kunt niet verder!',

    // database
    'database_details' => 'Hier stel je de verbinding met je database in.<br><br>Let goed op je instellingen, want ze moeten precies kloppen.',
    'driver' => 'Driver',
    'host' => 'Databasehost',
    'port' => 'Databasepoort',
    'port_help' => 'Laat dit leeg, tenzij je weet dat de server een afwijkende poort gebruikt.',
    'db_name' => 'Databasenaam',
    'db_username' => 'Databasegebruiker',
    'db_password' => 'Databasewachtwoord',
    'prefix' => 'Tabelvoorvoegsel',
    'prefix_help' => 'Vooral handig als je meerdere XGP-installaties in dezelfde database wilt draaien.',
    'db_check' => 'Verbinding testen',
    'db_connect_success' => 'Verbinding geslaagd!',
    'db_version_fail' => 'De databaseserver moet versie :version of nieuwer draaien. Huidige versie: :current.',
    'db_connect_fail' => 'De verbinding kon niet worden gemaakt. Controleer de gegevens en probeer het opnieuw!',

    // tables
    'tables_details' => 'De basisstructuur van de database is aangemaakt.<br><br><strong>Let op</strong> - dit script verwijdert alle bestaande tabellen en hun gegevens.',
    'results' => 'Resultatenlog',
    'wipe' => 'Database leeggemaakt',
    'clear_cache' => 'Cache geleegd',
    'prepare_config' => 'Configuratie klaar',
    'install' => 'Basistabellen klaar',
    'create' => 'Speltabellen klaar',
    'mark_updates' => 'Updates vastgelegd',
    'insert_changelog' => 'Wijzigingslog ingevoegd',
    'insert_languages' => 'Talen ingevoegd',
    'insert_options' => 'Opties ingevoegd',
    'no_logs' => 'Geen logs - Vernieuw de pagina om de installatie opnieuw te proberen.',
    'error_install' => 'Fout bij het installeren van het spel! Vernieuw de pagina om het opnieuw te proberen!',
    'success_install' => 'Het spel is met succes geïnstalleerd!',

    // admin
    'admin_details' => 'In deze stap maak je je beheerdersaccount aan.<br><br>Bewaar je inloggegevens goed! Gebruik zo mogelijk een wachtwoordbeheerder zoals <a href="https://1password.com/" target="_blank">1Password</a>, <a href="https://www.lastpass.com/" target="_blank">LastPass</a> of die van je browser, met een heel sterk wachtwoord.',
    'admin_email' => 'E-mailadres van de beheerder',
    'admin_email_help' => 'Hiermee log je in op het beheerpaneel',
    'admin_username' => 'Gebruikersnaam van de beheerder',
    'admin_username_help' => 'Dit is de naam die andere spelers in het spel zien.',
    'admin_password' => 'Wachtwoord van de beheerder',
    'admin_password_confirm' => 'Bevestig het wachtwoord van de beheerder',
    'random_password' => 'Maak een heel sterk willekeurig wachtwoord voor me',
    'create_admin' => 'Beheerder aanmaken',
    'admin_create_success' => 'Beheerder met succes aangemaakt!',
    'admin_create_fail' => 'De beheerder kon niet worden aangemaakt. Controleer de gegevens en probeer het opnieuw!',

    // final
    'final_details' => 'In deze stap doe je enkele laatste controles en beveilig je je spel verder.<br><br>Tijd om op te ruimen!',
    'final_install_not_accessible' => 'Installatie NIET bereikbaar',
    'final_install_not_accessible_ok' => 'OK',
    'final_install_not_accessible_need' => 'De installatie is bereikbaar',
    'final_config_writable' => 'Configuratiemap NIET beschrijfbaar',
    'final_config_writable_ok' => 'OK',
    'final_config_writable_need' => ':file is beschrijfbaar',
    'final_fail' => 'Je moet het spel als geïnstalleerd markeren en zorgen dat het configuratiebestand niet beschrijfbaar is!',
];
