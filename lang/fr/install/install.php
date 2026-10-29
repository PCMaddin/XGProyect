<?php

declare(strict_types=1);

return [
    // sidebar
    'sidebar_heading' => 'Installation',
    'steps' => 'Étapes',
    'requirements' => 'Prérequis',
    'database' => 'Base de données',
    'tables' => 'Créer les tables',
    'admin' => 'Administrateur',
    'final' => 'Dernières étapes',
    'finish' => 'Terminer',

    // next step notices
    'index_notice' => 'À l’étape suivante, nous vérifierons si vous pouvez installer XGP',
    'requirements_notice' => 'À l’étape suivante, nous commencerons l’installation de XGP 🎉',
    'database_notice' => 'À l’étape suivante, nous créerons les tables et les remplirons de données. <strong>Attention !</strong> L’étape suivante videra la base de données !',
    'tables_notice' => 'À l’étape suivante, nous créerons le compte administrateur.',
    'admin_notice' => 'À l’étape suivante, nous effectuerons quelques vérifications finales.',
    'final_notice' => 'Tout est prêt ! 🎉 Direction le panneau d’administration',

    // home
    'welcome' => 'Bienvenue sur XG Proyect !',
    'introduction' => '<strong>XG Proyect</strong>, aussi appelé XGP, est le clone d’OGame le plus abouti que vous puissiez trouver. <strong>XGP 4</strong> est la version la plus récente et la plus fiable jamais développée. Comme les versions précédentes, XG Proyect est soutenu par l’équipe autrefois connue sous le nom de Xtreme-gameZ, gage de qualité et de stabilité.<br><br>XG Proyect 4 vise une croissance constante, la stabilité, la flexibilité, le dynamisme, la qualité et la confiance des utilisateurs. Nous voulons toujours que XG Proyect dépasse vos attentes.<br><br>L’assistant d’installation vous guidera pas à pas. Pour toute question ou tout problème, n’hésitez pas à visiter notre <a href="https://www.xgproyect.org/" target="_blank"><em>communauté de support et de développement sur GitHub</em></a>.',
    'license' => 'Licence',
    'accept' => 'C’est compris !',

    // requirements
    'requirements_details' => 'Voici les prérequis minimaux pour faire fonctionner XGP.<br><br>Pour l’instant, seuls MySQL / MariaDB sont pris en charge, d’autres pilotes suivront. La version du serveur de base de données est vérifiée une fois les informations de connexion saisies.',
    'php_version_check' => 'Version de PHP :version',
    'php_version_current' => 'Vous avez :php',
    'php_version_need' => 'Il vous faut au moins :version',
    'mysql_check' => 'MySQL / MariaDB :version+',
    'mysql_check_current' => 'Vérifié après la saisie des identifiants de la base de données',
    'mysql_check_need' => 'Il vous faut MySQL / MariaDB :version ou plus récent',
    'config_writable' => 'Dossier de configuration accessible en écriture',
    'config_writable_ok' => 'OK',
    'config_writable_need' => ':file n’est pas accessible en écriture',
    'php_ext_check' => 'Extensions PHP',
    'php_ext_check_ok' => 'OK',
    'php_ext_check_need' => 'Il vous faut : :ext',
    'requirements_fail' => 'Votre système ne remplit pas les prérequis, vous ne pouvez pas continuer !',

    // database
    'database_details' => 'Ici, vous pouvez configurer la connexion à votre base de données.<br><br>Soyez attentif à vos paramètres : ils doivent être exactement identiques.',
    'driver' => 'Pilote',
    'host' => 'Hôte de la base de données',
    'port' => 'Port de la base de données',
    'port_help' => 'Laissez vide, sauf si vous savez que le serveur utilise un port non standard.',
    'db_name' => 'Nom de la base de données',
    'db_username' => 'Utilisateur de la base de données',
    'db_password' => 'Mot de passe de la base de données',
    'prefix' => 'Préfixe des tables',
    'prefix_help' => 'Surtout utile si vous prévoyez plusieurs installations de XGP dans la même base de données.',
    'db_check' => 'Tester la connexion',
    'db_connect_success' => 'Connexion établie avec succès !',
    'db_version_fail' => 'Le serveur de base de données doit être en version :version ou plus récente. Version actuelle : :current.',
    'db_connect_fail' => 'La connexion n’a pas pu être établie. Vérifiez les paramètres et réessayez !',

    // tables
    'tables_details' => 'La structure de base de la base de données est enfin créée.<br><br><strong>Attention</strong> - ce script supprime toutes les tables existantes et leurs données.',
    'results' => 'Journal des résultats',
    'wipe' => 'Base de données vidée',
    'clear_cache' => 'Cache vidé',
    'prepare_config' => 'Configuration prête',
    'install' => 'Tables de base prêtes',
    'create' => 'Tables du jeu prêtes',
    'mark_updates' => 'Mises à jour enregistrées',
    'insert_changelog' => 'Entrées du journal des modifications insérées',
    'insert_languages' => 'Langues insérées',
    'insert_options' => 'Options insérées',
    'no_logs' => 'Aucun journal - Actualisez la page pour relancer l’installation.',
    'error_install' => 'Erreur lors de l’installation du jeu ! Actualisez la page pour réessayer !',
    'success_install' => 'Le jeu a été installé avec succès !',

    // admin
    'admin_details' => 'À cette étape, vous pouvez créer votre compte administrateur.<br><br>Conservez vos identifiants en lieu sûr ! Si possible, utilisez un gestionnaire de mots de passe comme <a href="https://1password.com/" target="_blank">1Password</a>, <a href="https://www.lastpass.com/" target="_blank">LastPass</a> ou celui de votre navigateur, avec un mot de passe très sûr.',
    'admin_email' => 'Adresse e-mail de l’administrateur',
    'admin_email_help' => 'Elle sert à se connecter au panneau d’administration',
    'admin_username' => 'Nom d’utilisateur de l’administrateur',
    'admin_username_help' => 'C’est le nom que les autres joueurs verront dans le jeu.',
    'admin_password' => 'Mot de passe de l’administrateur',
    'admin_password_confirm' => 'Confirmer le mot de passe de l’administrateur',
    'random_password' => 'Générer pour moi un mot de passe aléatoire très sûr',
    'create_admin' => 'Créer l’administrateur',
    'admin_create_success' => 'Administrateur créé avec succès !',
    'admin_create_fail' => 'L’administrateur n’a pas pu être créé. Vérifiez les données et réessayez !',

    // final
    'final_details' => 'À cette étape, vous pouvez effectuer quelques vérifications finales et mieux sécuriser votre jeu.<br><br>Faisons un peu de ménage !',
    'final_install_not_accessible' => 'Installation NON accessible',
    'final_install_not_accessible_ok' => 'OK',
    'final_install_not_accessible_need' => 'L’installation est accessible',
    'final_config_writable' => 'Dossier de configuration NON accessible en écriture',
    'final_config_writable_ok' => 'OK',
    'final_config_writable_need' => ':file est accessible en écriture',
    'final_fail' => 'Vous devez marquer le jeu comme installé et vous assurer que le fichier de configuration n’est pas accessible en écriture !',
];
