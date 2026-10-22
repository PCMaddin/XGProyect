<?php

declare(strict_types=1);

return [
    // sidebar
    'sidebar_heading' => 'Instalação',
    'steps' => 'Etapas',
    'requirements' => 'Requisitos',
    'database' => 'Banco de dados',
    'tables' => 'Criar tabelas',
    'admin' => 'Administrador',
    'final' => 'Etapas finais',
    'finish' => 'Concluir',

    // next step notices
    'index_notice' => 'Na próxima etapa verificaremos se você pode instalar o XGP',
    'requirements_notice' => 'Na próxima etapa começaremos a instalar o XGP 🎉',
    'database_notice' => 'Na próxima etapa criaremos as tabelas e as preencheremos com dados. <strong>Atenção!</strong> A próxima etapa vai limpar o banco de dados!',
    'tables_notice' => 'Na próxima etapa criaremos a conta de administrador.',
    'admin_notice' => 'Na próxima etapa faremos algumas verificações finais.',
    'final_notice' => 'Tudo pronto! 🎉 Vamos ao painel de administração',

    // home
    'welcome' => 'Bem-vindo ao XG Proyect!',
    'introduction' => '<strong>XG Proyect</strong>, também conhecido como XGP, é o clone de OGame mais completo que você vai encontrar. <strong>XGP 4</strong> é a versão mais recente e confiável já desenvolvida. Como as versões anteriores, o XG Proyect conta com o apoio da equipe antes conhecida como Xtreme-gameZ, garantindo qualidade e estabilidade.<br><br>O XG Proyect 4 busca crescimento constante, estabilidade, flexibilidade, dinamismo, qualidade e a confiança dos usuários. Nosso objetivo é sempre superar suas expectativas.<br><br>O sistema de instalação vai guiar você por todo o processo. Em caso de dúvidas ou problemas, visite nossa <a href="https://www.xgproyect.org/" target="_blank"><em>comunidade de suporte e desenvolvimento no GitHub</em></a>.',
    'license' => 'Licença',
    'accept' => 'Entendi!',

    // requirements
    'requirements_details' => 'Estes são os requisitos mínimos para executar o XGP.<br><br>Por enquanto só há suporte a MySQL / MariaDB, mas pretendemos suportar outros drivers no futuro. A versão do servidor de banco de dados é verificada depois que você informa os dados de conexão.',
    'php_version_check' => 'Versão do PHP :version',
    'php_version_current' => 'Você tem :php',
    'php_version_need' => 'Você precisa de pelo menos :version',
    'mysql_check' => 'MySQL / MariaDB :version+',
    'mysql_check_current' => 'Verificado depois que você informar os dados do banco de dados',
    'mysql_check_need' => 'Você precisa do MySQL / MariaDB :version ou mais recente',
    'config_writable' => 'Pasta de configuração gravável',
    'config_writable_ok' => 'OK',
    'config_writable_need' => ':file não é gravável',
    'php_ext_check' => 'Extensões do PHP',
    'php_ext_check_ok' => 'OK',
    'php_ext_check_need' => 'Você precisa de: :ext',
    'requirements_fail' => 'Seu sistema não atende aos requisitos, não é possível continuar!',

    // database
    'database_details' => 'Aqui você configura a conexão com o banco de dados.<br><br>Preste atenção aos dados, pois eles precisam estar exatamente corretos.',
    'driver' => 'Driver',
    'host' => 'Servidor do banco de dados',
    'port' => 'Porta do banco de dados',
    'port_help' => 'Deixe em branco, a menos que você saiba que o servidor usa uma porta não padrão.',
    'db_name' => 'Nome do banco de dados',
    'db_username' => 'Usuário do banco de dados',
    'db_password' => 'Senha do banco de dados',
    'prefix' => 'Prefixo das tabelas',
    'prefix_help' => 'Útil principalmente se você pretende ter várias instalações do XGP no mesmo banco de dados.',
    'db_check' => 'Testar conexão',
    'db_connect_success' => 'Conexão estabelecida com sucesso!',
    'db_version_fail' => 'O servidor de banco de dados precisa estar na versão :version ou mais recente. Versão atual: :current.',
    'db_connect_fail' => 'Não foi possível estabelecer a conexão. Confira os dados e tente novamente!',

    // tables
    'tables_details' => 'A estrutura básica do banco de dados foi criada.<br><br><strong>Cuidado</strong> - este script apaga todas as tabelas existentes e seus registros.',
    'results' => 'Registro de resultados',
    'wipe' => 'Banco de dados limpo',
    'clear_cache' => 'Cache limpo',
    'prepare_config' => 'Configuração pronta',
    'install' => 'Tabelas básicas prontas',
    'create' => 'Tabelas do jogo prontas',
    'mark_updates' => 'Atualizações registradas',
    'insert_changelog' => 'Registros do histórico de mudanças inseridos',
    'insert_languages' => 'Idiomas inseridos',
    'insert_options' => 'Opções inseridas',
    'no_logs' => 'Nenhum registro - Atualize a página para tentar a instalação de novo.',
    'error_install' => 'Erro ao instalar o jogo! Atualize a página para tentar de novo!',
    'success_install' => 'O jogo foi instalado com sucesso!',

    // admin
    'admin_details' => 'Nesta etapa você cria sua conta de administrador.<br><br>Guarde as credenciais em local seguro! Se possível, use um gerenciador de senhas como <a href="https://1password.com/" target="_blank">1Password</a>, <a href="https://www.lastpass.com/" target="_blank">LastPass</a> ou o do seu navegador, com uma senha bem segura.',
    'admin_email' => 'E-mail do administrador',
    'admin_email_help' => 'Usado para entrar no painel de administração',
    'admin_username' => 'Nome de usuário do administrador',
    'admin_username_help' => 'É o nome que os outros jogadores verão no jogo.',
    'admin_password' => 'Senha do administrador',
    'admin_password_confirm' => 'Confirme a senha do administrador',
    'random_password' => 'Gerar para mim uma senha aleatória bem segura',
    'create_admin' => 'Criar administrador',
    'admin_create_success' => 'Administrador criado com sucesso!',
    'admin_create_fail' => 'Não foi possível criar o administrador. Confira os dados e tente novamente!',

    // final
    'final_details' => 'Nesta etapa você faz algumas verificações finais e deixa seu jogo mais seguro.<br><br>Vamos fazer uma limpeza!',
    'final_install_not_accessible' => 'Instalação NÃO acessível',
    'final_install_not_accessible_ok' => 'OK',
    'final_install_not_accessible_need' => 'A instalação está acessível',
    'final_config_writable' => 'Pasta de configuração NÃO gravável',
    'final_config_writable_ok' => 'OK',
    'final_config_writable_need' => ':file é gravável',
    'final_fail' => 'Você precisa marcar o jogo como instalado e garantir que o arquivo de configuração não seja gravável!',
];
