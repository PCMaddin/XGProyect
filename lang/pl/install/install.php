<?php

declare(strict_types=1);

return [
    // sidebar
    'sidebar_heading' => 'Instalacja',
    'steps' => 'Kroki',
    'requirements' => 'Wymagania',
    'database' => 'Ustawienia bazy danych',
    'tables' => 'Tworzenie tabel',
    'admin' => 'Dane administratora',
    'final' => 'Ostatnie kroki',
    'finish' => 'Zakończ',

    // next step notices
    'index_notice' => 'W następnym kroku sprawdzimy, czy możesz zainstalować XGP',
    'requirements_notice' => 'W następnym kroku zaczniemy instalować XGP 🎉',
    'database_notice' => 'W następnym kroku zaczniemy tworzyć tabele i wypełniać je danymi. <strong>Uwaga!</strong> Następny krok wyczyści bazę danych!',
    'tables_notice' => 'W następnym kroku utworzymy konto administratora.',
    'admin_notice' => 'W następnym kroku wykonamy ostatnie sprawdzenia.',
    'final_notice' => 'Gotowe! 🎉 Przejdźmy do panelu administratora',

    // home
    'welcome' => 'Witaj w XG Proyect!',
    'introduction' => '<strong>XG Proyect</strong>, znany też jako XGP, to najlepszy klon OGame, jaki znajdziesz. <strong>XGP 4</strong> to najnowszy i najbardziej niezawodny pakiet, jaki kiedykolwiek powstał. Podobnie jak poprzednie wersje, XG Proyect jest wspierany przez zespół dawniej znany jako Xtreme-gameZ, co zapewnia najwyższą jakość opieki i stabilność wydań.<br><br>XG Proyect 4 stawia na ciągły rozwój, stabilność, elastyczność, dynamikę, jakość i zaufanie użytkowników. Zawsze dążymy do tego, by XG Proyect przerastał twoje oczekiwania.<br><br>System instalacji płynnie przeprowadzi cię przez cały proces. Jeśli masz wątpliwości, problemy lub pytania, odwiedź naszą <a href="https://www.xgproyect.org/" target="_blank"><em>społeczność wsparcia i rozwoju na GitHubie</em></a>.',
    'license' => 'Licencja',
    'accept' => 'Jasne, rozumiem!',

    // requirements
    'requirements_details' => 'To minimalne wymagania do uruchomienia XGP.<br><br>Obecnie obsługujemy tylko MySQL / MariaDB, ale w przyszłości planujemy obsługę innych sterowników. Wersja serwera bazy danych jest sprawdzana po podaniu danych połączenia.',
    'php_version_check' => 'Wersja PHP :version',
    'php_version_current' => 'Masz :php',
    'php_version_need' => 'Potrzebujesz co najmniej :version',
    'mysql_check' => 'MySQL / MariaDB :version+',
    'mysql_check_current' => 'Sprawdzane po podaniu danych dostępu do bazy',
    'mysql_check_need' => 'Potrzebujesz MySQL / MariaDB :version lub nowszego',
    'config_writable' => 'Zapisywalna ścieżka konfiguracji',
    'config_writable_ok' => 'OK',
    'config_writable_need' => ':file nie jest zapisywalny',
    'php_ext_check' => 'Rozszerzenia PHP',
    'php_ext_check_ok' => 'OK',
    'php_ext_check_need' => 'Potrzebujesz: :ext',
    'requirements_fail' => 'Twój system nie spełnia wymagań, nie możesz kontynuować!',

    // database
    'database_details' => 'Tutaj ustawisz dane połączenia z bazą danych.<br><br>Zwróć uwagę na ustawienia, muszą być dokładnie takie same.',
    'driver' => 'Sterownik',
    'host' => 'Host bazy danych',
    'port' => 'Port bazy danych',
    'port_help' => 'Zostaw puste, chyba że wiesz, że serwer działa na niestandardowym porcie.',
    'db_name' => 'Nazwa bazy danych',
    'db_username' => 'Użytkownik bazy danych',
    'db_password' => 'Hasło bazy danych',
    'prefix' => 'Prefiks tabel',
    'prefix_help' => 'Przydatny głównie, jeśli planujesz kilka instalacji XGP w tej samej bazie danych.',
    'db_check' => 'Spróbuj połączyć',
    'db_connect_success' => 'Połączenie nawiązane!',
    'db_version_fail' => 'Serwer bazy danych musi działać w wersji :version lub nowszej. Obecna wersja: :current.',
    'db_connect_fail' => 'Nie udało się nawiązać połączenia. Sprawdź dane i spróbuj ponownie!',

    // tables
    'tables_details' => 'Podstawowa struktura bazy danych została utworzona.<br><br><strong>Uwaga</strong> - ten skrypt usunie wszystkie istniejące tabele wraz z ich rekordami.',
    'results' => 'Dziennik wyników',
    'wipe' => 'Baza danych wyczyszczona',
    'clear_cache' => 'Pamięć podręczna wyczyszczona',
    'prepare_config' => 'Konfiguracja gotowa',
    'install' => 'Tabele podstawowe gotowe',
    'create' => 'Tabele gry gotowe',
    'mark_updates' => 'Rekordy aktualizacji zapisane',
    'insert_changelog' => 'Rekordy listy zmian dodane',
    'insert_languages' => 'Rekordy języków dodane',
    'insert_options' => 'Rekordy opcji dodane',
    'no_logs' => 'Brak wpisów - odśwież stronę, aby ponowić instalację.',
    'error_install' => 'Błąd instalacji gry! Odśwież stronę, aby spróbować ponownie!',
    'success_install' => 'Gra została zainstalowana!',

    // admin
    'admin_details' => 'W tym kroku utworzysz konto administratora.<br><br>Przechowuj dane logowania w bezpiecznym miejscu! Jeśli to możliwe, użyj menedżera haseł, takiego jak <a href="https://1password.com/" target="_blank">1Password</a>, <a href="https://www.lastpass.com/" target="_blank">LastPass</a> lub wbudowanego w przeglądarkę, i ustaw bardzo bezpieczne hasło.',
    'admin_email' => 'Adres e-mail administratora',
    'admin_email_help' => 'Służy do logowania do panelu administratora',
    'admin_username' => 'Nazwa administratora',
    'admin_username_help' => 'Pod tą nazwą będą cię widzieć inni gracze w grze.',
    'admin_password' => 'Hasło administratora',
    'admin_password_confirm' => 'Potwierdź hasło administratora',
    'random_password' => 'Wygeneruj dla mnie bardzo bezpieczne losowe hasło',
    'create_admin' => 'Utwórz administratora',
    'admin_create_success' => 'Administrator utworzony!',
    'admin_create_fail' => 'Nie udało się utworzyć administratora. Sprawdź dane i spróbuj ponownie!',

    // final
    'final_details' => 'W tym kroku wykonasz ostatnie sprawdzenia i dodatkowo zabezpieczysz grę.<br><br>Posprzątajmy!',
    'final_install_not_accessible' => 'Instalacja NIE jest dostępna',
    'final_install_not_accessible_ok' => 'OK',
    'final_install_not_accessible_need' => 'Instalacja jest dostępna',
    'final_config_writable' => 'Ścieżka konfiguracji NIE jest zapisywalna',
    'final_config_writable_ok' => 'OK',
    'final_config_writable_need' => ':file jest zapisywalny',
    'final_fail' => 'Musisz oznaczyć grę jako zainstalowaną i upewnić się, że plik konfiguracyjny nie jest zapisywalny!',
];
