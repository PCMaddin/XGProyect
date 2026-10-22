<?php

declare(strict_types=1);

return [
    // sidebar
    'sidebar_heading' => 'インストール',
    'steps' => 'ステップ',
    'requirements' => '動作要件',
    'database' => 'データベース設定',
    'tables' => 'テーブル作成',
    'admin' => '管理者情報',
    'final' => '最終ステップ',
    'finish' => '完了',

    // next step notices
    'index_notice' => '次のステップでは、XGP をインストールできるか確認します',
    'requirements_notice' => '次のステップで XGP のインストールを開始します 🎉',
    'database_notice' => '次のステップでテーブルを作成し、データを投入します。<strong>警告！</strong>次のステップでデータベースが消去されます！',
    'tables_notice' => '次のステップで管理者アカウントを作成します。',
    'admin_notice' => '次のステップで最終確認を行います。',
    'final_notice' => 'すべて完了しました！🎉 管理パネルへ移動しましょう',

    // home
    'welcome' => 'XG Proyect へようこそ！',
    'introduction' => '<strong>XG Proyect</strong>（XGP）は、最高の OGame クローンです。<strong>XGP 4</strong> はこれまでで最新かつ最も信頼性の高いパッケージです。以前のバージョンと同様に、XG Proyect は旧 Xtreme-gameZ チームのサポートを受けており、最高品質のケアとリリースの安定性を保証しています。<br><br>XG Proyect 4 は、継続的な成長、安定性、柔軟性、ダイナミズム、品質、そしてユーザーの信頼に力を注いでいます。XG Proyect が常に期待を超えるものであるよう努めています。<br><br>インストールシステムがインストール手順をスムーズに案内します。疑問や問題、質問がある場合は、<a href="https://www.xgproyect.org/" target="_blank"><em>GitHub のサポート・開発コミュニティ</em></a>をお気軽にご覧ください。',
    'license' => 'ライセンス',
    'accept' => 'わかりました！',

    // requirements
    'requirements_details' => 'これらは XGP を動作させるための最低要件です。<br><br>現在は MySQL / MariaDB のみに対応していますが、将来的には他のドライバーにも対応する予定です。データベースサーバーのバージョンは接続情報を入力した後に確認されます。',
    'php_version_check' => 'PHP バージョン :version',
    'php_version_current' => '現在のバージョン: :php',
    'php_version_need' => ':version 以上が必要です',
    'mysql_check' => 'MySQL / MariaDB :version+',
    'mysql_check_current' => 'データベースの認証情報を入力した後に確認されます',
    'mysql_check_need' => 'MySQL / MariaDB :version 以降が必要です',
    'config_writable' => '設定パスの書き込み権限',
    'config_writable_ok' => 'OK',
    'config_writable_need' => ':file は書き込みできません',
    'php_ext_check' => 'PHP 拡張機能',
    'php_ext_check_ok' => 'OK',
    'php_ext_check_need' => '必要な拡張機能: :ext',
    'requirements_fail' => 'システムが要件を満たしていないため、続行できません！',

    // database
    'database_details' => 'ここではデータベースの接続情報を設定します。<br><br>設定は正確に一致している必要があるため、注意して入力してください。',
    'driver' => 'ドライバー',
    'host' => 'データベースホスト',
    'port' => 'データベースポート',
    'port_help' => 'サーバーが標準以外のポートを使用している場合を除き、空欄のままにしてください。',
    'db_name' => 'データベース名',
    'db_username' => 'データベースのユーザー名',
    'db_password' => 'データベースのパスワード',
    'prefix' => 'テーブル接頭辞',
    'prefix_help' => '同じデータベースで複数の XGP を運用する場合に便利です。',
    'db_check' => '接続を試す',
    'db_connect_success' => '接続に成功しました！',
    'db_version_fail' => 'データベースサーバーはバージョン :version 以降である必要があります。現在のバージョン: :current。',
    'db_connect_fail' => '接続できませんでした。設定を確認してもう一度お試しください！',

    // tables
    'tables_details' => 'データベースの基本構造が作成されました。<br><br><strong>注意</strong> - このスクリプトは既存のテーブルとそのレコードをすべて削除します。',
    'results' => '結果ログ',
    'wipe' => 'データベースを消去しました',
    'clear_cache' => 'キャッシュをクリアしました',
    'prepare_config' => '設定の準備完了',
    'install' => '基本テーブルの準備完了',
    'create' => 'ゲームテーブルの準備完了',
    'mark_updates' => 'アップデート記録を書き込みました',
    'insert_changelog' => '更新履歴のレコードを挿入しました',
    'insert_languages' => '言語のレコードを挿入しました',
    'insert_options' => 'オプションのレコードを挿入しました',
    'no_logs' => 'ログがありません - インストールを再試行するにはページを再読み込みしてください。',
    'error_install' => 'ゲームのインストール中にエラーが発生しました！ページを再読み込みしてもう一度お試しください！',
    'success_install' => 'ゲームのインストールが完了しました！',

    // admin
    'admin_details' => 'このステップでは管理者アカウントを作成します。<br><br>認証情報は安全に保管してください！可能であれば <a href="https://1password.com/" target="_blank">1Password</a>、<a href="https://www.lastpass.com/" target="_blank">LastPass</a>、またはブラウザ標準のパスワードマネージャーを使い、非常に安全なパスワードを設定してください。',
    'admin_email' => '管理者のメールアドレス',
    'admin_email_help' => '管理パネルへのログインに使用します',
    'admin_username' => '管理者のユーザー名',
    'admin_username_help' => 'ゲーム内で他のプレイヤーに表示されます。',
    'admin_password' => '管理者のパスワード',
    'admin_password_confirm' => '管理者のパスワード（確認）',
    'random_password' => '非常に安全なランダムパスワードを生成する',
    'create_admin' => '管理者を作成',
    'admin_create_success' => '管理者を作成しました！',
    'admin_create_fail' => '管理者を作成できませんでした。入力内容を確認してもう一度お試しください！',

    // final
    'final_details' => 'このステップでは最終確認を行い、ゲームのセキュリティをさらに高めます。<br><br>後片付けをしましょう！',
    'final_install_not_accessible' => 'インストーラーにアクセスできないこと',
    'final_install_not_accessible_ok' => 'OK',
    'final_install_not_accessible_need' => 'インストーラーにアクセスできます',
    'final_config_writable' => '設定パスが書き込み不可であること',
    'final_config_writable_ok' => 'OK',
    'final_config_writable_need' => ':file は書き込み可能です',
    'final_fail' => 'ゲームをインストール済みにし、設定ファイルが書き込み不可であることを確認する必要があります！',
];
