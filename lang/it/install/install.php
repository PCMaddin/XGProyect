<?php

declare(strict_types=1);

return [
    // sidebar
    'sidebar_heading' => 'Installazione',
    'steps' => 'Passaggi',
    'requirements' => 'Requisiti',
    'database' => 'Impostazioni del database',
    'tables' => 'Crea tabelle',
    'admin' => 'Dati dell’amministratore',
    'final' => 'Ultimi passaggi',
    'finish' => 'Fine',

    // next step notices
    'index_notice' => 'Nel prossimo passaggio verificheremo se puoi installare XGP',
    'requirements_notice' => 'Nel prossimo passaggio inizieremo a installare XGP 🎉',
    'database_notice' => 'Nel prossimo passaggio creeremo le tabelle e le riempiremo di dati. <strong>Attenzione!</strong> Il prossimo passaggio svuoterà il database!',
    'tables_notice' => 'Nel prossimo passaggio creeremo l’account amministratore.',
    'admin_notice' => 'Nel prossimo passaggio faremo alcuni controlli finali.',
    'final_notice' => 'Tutto pronto! 🎉 Andiamo al pannello di amministrazione',

    // home
    'welcome' => 'Benvenuto in XG Proyect!',
    'introduction' => '<strong>XG Proyect</strong>, noto anche come XGP, è il clone di OGame più completo che tu possa trovare. <strong>XGP 4</strong> è la versione più recente e affidabile mai sviluppata. Come le versioni precedenti, XG Proyect è supportato dal team un tempo noto come Xtreme-gameZ, garanzia di qualità e stabilità.<br><br>XG Proyect 4 punta a crescita costante, stabilità, flessibilità, dinamismo, qualità e alla fiducia degli utenti. Vogliamo che XG Proyect superi sempre le tue aspettative.<br><br>Il sistema di installazione ti guiderà senza intoppi. Per dubbi, problemi o domande visita la nostra <a href="https://www.xgproyect.org/" target="_blank"><em>community di supporto e sviluppo su GitHub</em></a>.',
    'license' => 'Licenza',
    'accept' => 'Ho capito!',

    // requirements
    'requirements_details' => 'Questi sono i requisiti minimi per far funzionare XGP.<br><br>Al momento supportiamo solo MySQL / MariaDB, ma in futuro vogliamo supportare altri driver. La versione del server di database viene verificata dopo che hai inserito i dati di connessione.',
    'php_version_check' => 'Versione di PHP :version',
    'php_version_current' => 'Hai :php',
    'php_version_need' => 'Ti serve almeno :version',
    'mysql_check' => 'MySQL / MariaDB :version+',
    'mysql_check_current' => 'Verificato dopo l’inserimento dei dati del database',
    'mysql_check_need' => 'Ti serve MySQL / MariaDB :version o successivo',
    'config_writable' => 'Cartella di configurazione scrivibile',
    'config_writable_ok' => 'OK',
    'config_writable_need' => ':file non è scrivibile',
    'php_ext_check' => 'Estensioni PHP',
    'php_ext_check_ok' => 'OK',
    'php_ext_check_need' => 'Ti serve: :ext',
    'requirements_fail' => 'Il tuo sistema non soddisfa i requisiti, non puoi continuare!',

    // database
    'database_details' => 'Qui imposti la connessione al database.<br><br>Fai attenzione ai dati, perché devono essere esattamente corretti.',
    'driver' => 'Driver',
    'host' => 'Host del database',
    'port' => 'Porta del database',
    'port_help' => 'Lascia vuoto, a meno che tu sappia che il server usa una porta non standard.',
    'db_name' => 'Nome del database',
    'db_username' => 'Utente del database',
    'db_password' => 'Password del database',
    'prefix' => 'Prefisso delle tabelle',
    'prefix_help' => 'Utile soprattutto se vuoi più installazioni di XGP nello stesso database.',
    'db_check' => 'Prova la connessione',
    'db_connect_success' => 'Connessione riuscita!',
    'db_version_fail' => 'Il server di database deve essere alla versione :version o successiva. Versione attuale: :current.',
    'db_connect_fail' => 'Impossibile stabilire la connessione. Controlla i dati e riprova!',

    // tables
    'tables_details' => 'La struttura di base del database è stata creata.<br><br><strong>Attenzione</strong> - questo script elimina tutte le tabelle esistenti e i loro dati.',
    'results' => 'Registro dei risultati',
    'wipe' => 'Database svuotato',
    'clear_cache' => 'Cache svuotata',
    'prepare_config' => 'Configurazione pronta',
    'install' => 'Tabelle di base pronte',
    'create' => 'Tabelle del gioco pronte',
    'mark_updates' => 'Aggiornamenti registrati',
    'insert_changelog' => 'Voci del registro modifiche inserite',
    'insert_languages' => 'Lingue inserite',
    'insert_options' => 'Opzioni inserite',
    'no_logs' => 'Nessun registro - Ricarica la pagina per riprovare l’installazione.',
    'error_install' => 'Errore durante l’installazione del gioco! Ricarica la pagina per riprovare!',
    'success_install' => 'Il gioco è stato installato con successo!',

    // admin
    'admin_details' => 'In questo passaggio crei il tuo account amministratore.<br><br>Conserva le credenziali al sicuro! Se possibile usa un gestore di password come <a href="https://1password.com/" target="_blank">1Password</a>, <a href="https://www.lastpass.com/" target="_blank">LastPass</a> o quello del browser, con una password molto sicura.',
    'admin_email' => 'Indirizzo e-mail dell’amministratore',
    'admin_email_help' => 'Serve per accedere al pannello di amministrazione',
    'admin_username' => 'Nome utente dell’amministratore',
    'admin_username_help' => 'È il nome che gli altri giocatori vedranno nel gioco.',
    'admin_password' => 'Password dell’amministratore',
    'admin_password_confirm' => 'Conferma la password dell’amministratore',
    'random_password' => 'Genera per me una password casuale molto sicura',
    'create_admin' => 'Crea amministratore',
    'admin_create_success' => 'Amministratore creato con successo!',
    'admin_create_fail' => 'Impossibile creare l’amministratore. Controlla i dati e riprova!',

    // final
    'final_details' => 'In questo passaggio fai alcuni controlli finali e rendi il gioco più sicuro.<br><br>Facciamo un po’ di pulizia!',
    'final_install_not_accessible' => 'Installazione NON accessibile',
    'final_install_not_accessible_ok' => 'OK',
    'final_install_not_accessible_need' => 'L’installazione è accessibile',
    'final_config_writable' => 'Cartella di configurazione NON scrivibile',
    'final_config_writable_ok' => 'OK',
    'final_config_writable_need' => ':file è scrivibile',
    'final_fail' => 'Devi segnare il gioco come installato e assicurarti che il file di configurazione non sia scrivibile!',
];
