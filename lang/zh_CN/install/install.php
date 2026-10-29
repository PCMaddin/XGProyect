<?php

declare(strict_types=1);

return [
    // sidebar
    'sidebar_heading' => '安装',
    'steps' => '步骤',
    'requirements' => '系统要求',
    'database' => '数据库设置',
    'tables' => '创建数据表',
    'admin' => '管理员信息',
    'final' => '最后步骤',
    'finish' => '完成',

    // next step notices
    'index_notice' => '下一步我们将检查你是否可以安装 XGP',
    'requirements_notice' => '下一步我们将开始安装 XGP 🎉',
    'database_notice' => '下一步我们将开始创建数据表并填充数据。<strong>警告！</strong>下一步将清空数据库！',
    'tables_notice' => '下一步我们将创建管理员账号。',
    'admin_notice' => '下一步我们将进行最后的检查。',
    'final_notice' => '全部完成！🎉 前往管理面板吧',

    // home
    'welcome' => '欢迎使用 XG Proyect！',
    'introduction' => '<strong>XG Proyect</strong>（也称 XGP）是你能找到的终极 OGame 克隆版。<strong>XGP 4</strong> 是迄今为止最新、最可靠的版本。与以前的版本一样，XG Proyect 由原 Xtreme-gameZ 团队提供支持，确保一流的维护和版本稳定性。<br><br>XG Proyect 4 致力于持续发展、稳定、灵活、活力、品质和用户信赖。我们始终希望 XG Proyect 超出你的期望。<br><br>安装系统将引导你顺利完成安装过程。如有任何疑问、问题或咨询，欢迎访问我们的 <a href="https://www.xgproyect.org/" target="_blank"><em>GitHub 支持与开发社区</em></a>。',
    'license' => '许可证',
    'accept' => '好的，明白了！',

    // requirements
    'requirements_details' => '这些是运行 XGP 的最低要求。<br><br>目前我们只支持 MySQL / MariaDB，但将来计划支持其他驱动。数据库服务器版本会在你输入连接信息后进行验证。',
    'php_version_check' => 'PHP 版本 :version',
    'php_version_current' => '你的版本: :php',
    'php_version_need' => '至少需要 :version',
    'mysql_check' => 'MySQL / MariaDB :version+',
    'mysql_check_current' => '输入数据库凭据后进行验证',
    'mysql_check_need' => '需要 MySQL / MariaDB :version 或更高版本',
    'config_writable' => '配置路径可写',
    'config_writable_ok' => 'OK',
    'config_writable_need' => ':file 不可写',
    'php_ext_check' => 'PHP 扩展',
    'php_ext_check_ok' => 'OK',
    'php_ext_check_need' => '你需要: :ext',
    'requirements_fail' => '你的系统不满足要求，无法继续！',

    // database
    'database_details' => '你可以在这里设置数据库连接信息。<br><br>请注意你的设置，它们必须完全一致。',
    'driver' => '驱动',
    'host' => '数据库主机',
    'port' => '数据库端口',
    'port_help' => '除非你知道服务器使用非标准端口，否则请留空。',
    'db_name' => '数据库名称',
    'db_username' => '数据库用户名',
    'db_password' => '数据库密码',
    'prefix' => '表前缀',
    'prefix_help' => '如果你打算在同一个数据库中运行多个 XGP 安装，这会很有用。',
    'db_check' => '尝试连接',
    'db_connect_success' => '连接成功！',
    'db_version_fail' => '数据库服务器必须为 :version 或更高版本。当前版本: :current。',
    'db_connect_fail' => '无法建立连接。请检查后重试！',

    // tables
    'tables_details' => '数据库的基本结构已创建完成。<br><br><strong>注意</strong> - 此脚本会删除所有现有的数据表及其记录。',
    'results' => '结果日志',
    'wipe' => '数据库已清空',
    'clear_cache' => '缓存已清除',
    'prepare_config' => '配置已就绪',
    'install' => '基础数据表已就绪',
    'create' => '游戏数据表已就绪',
    'mark_updates' => '更新记录已写入',
    'insert_changelog' => '更新日志记录已插入',
    'insert_languages' => '语言记录已插入',
    'insert_options' => '选项记录已插入',
    'no_logs' => '没有日志 - 需要刷新页面重新尝试安装。',
    'error_install' => '游戏安装出错！请刷新页面重试！',
    'success_install' => '游戏安装成功！',

    // admin
    'admin_details' => '在此步骤中，你可以创建管理员账号。<br><br>请妥善保管登录凭据！如果可能，请使用 <a href="https://1password.com/" target="_blank">1Password</a>、<a href="https://www.lastpass.com/" target="_blank">LastPass</a> 或浏览器自带的密码管理器，并设置一个高强度密码。',
    'admin_email' => '管理员电子邮箱',
    'admin_email_help' => '用于登录管理面板',
    'admin_username' => '管理员用户名',
    'admin_username_help' => '在游戏中向其他玩家显示的名称。',
    'admin_password' => '管理员密码',
    'admin_password_confirm' => '确认管理员密码',
    'random_password' => '为我生成一个高强度随机密码',
    'create_admin' => '创建管理员',
    'admin_create_success' => '管理员创建成功！',
    'admin_create_fail' => '无法创建管理员。请检查后重试！',

    // final
    'final_details' => '在此步骤中，你可以进行最后的检查并进一步保护你的游戏。<br><br>让我们来清理一下吧！',
    'final_install_not_accessible' => '安装程序不可访问',
    'final_install_not_accessible_ok' => 'OK',
    'final_install_not_accessible_need' => '安装程序可访问',
    'final_config_writable' => '配置路径不可写',
    'final_config_writable_ok' => 'OK',
    'final_config_writable_need' => ':file 可写',
    'final_fail' => '你需要将游戏标记为已安装，并确保配置文件不可写！',
];
