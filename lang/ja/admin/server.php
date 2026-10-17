<?php

declare(strict_types=1);

return [
    'se_server_parameters' => 'サーバー設定',
    'se_sub_title' => 'ここでは、宇宙の速度、艦隊オプション、経済レートなど、サーバーの基本設定をすべて行えます。',
    // Section headings
    'se_section_identity' => '基本情報',
    'se_section_speed' => '速度と経済',
    'se_section_access' => 'サーバーへのアクセス',
    'se_section_datetime' => '日付と時刻',
    'se_section_combat' => '戦闘ルール',
    // Identity
    'se_name' => '名前',
    'se_logo' => 'ロゴ',
    'se_lang' => '言語',
    'se_admin_email' => '管理者のメールアドレス',
    'se_forum_link' => 'フォーラムのリンク',
    'se_server_name' => 'サーバー名',
    'se_server_logo' => 'サーバーのロゴ。URL のみ。',
    // Speed & Economy
    'se_general_speed' => '全体速度',
    'se_normal_speed' => 'デフォルトの全体速度: 1<br>推奨最大速度: 100',
    'se_normal_speed_resoruces' => 'デフォルトの生産速度: 1<br>推奨最大速度: 100',
    'se_normal_speed_fleett' => 'デフォルトの艦隊速度: 1<br>推奨最大速度: 100',
    'se_fleet_speed' => '艦隊速度',
    'se_resources_producion_speed' => '生産速度',
    // Server access
    'se_server_op_close' => 'サーバーを有効にする',
    'se_server_status_message' => 'サーバー無効時のメッセージ',
    // Date & Time
    'se_date_time_zone' => 'タイムゾーン',
    'se_date_format' => '短い日付',
    'se_date_format_extended' => '詳細な日付',
    // Combat rules
    'se_several_parameters' => 'その他の設定',
    'se_title_admins_protection' => 'チェックを入れると保護が有効になり、管理者とモデレーターは攻撃やその他の艦隊行動を受けなくなります。',
    'se_admin_protection' => '管理者の保護',
    'se_def_cdr' => '防衛からのデブリ',
    'se_ships_cdr' => '艦船からのデブリ',
    'se_def_cdr_message' => '攻撃後に防衛から生じるデブリの割合を設定します',
    'se_ships_cdr_message' => '攻撃後に艦船から生じるデブリの割合を設定します',
    // Noob protection
    'se_noob_protect' => '初心者保護',
    'se_noob_protect_active' => '保護を有効にする',
    'se_noob_protect2' => '基準ポイント',
    'se_noob_protect3' => '倍率',
    'se_noob_protect_e2' => '保護が解除されるポイントの上限',
    'se_noob_protect_e3' => '倍率の仕組みは例で説明するのが一番わかりやすいでしょう:<br>
    プレイヤーのポイント * 倍率 = そのプレイヤーを攻撃できるポイントの上限<br>
    <br>
    例:<br>
    プレイヤーが 10 ポイントで、倍率が 5 の場合:<br>
    <br>
    10 * 5 = 50<br>
    <br>
    50 ポイントまでのプレイヤーはこの弱いプレイヤーを攻撃できますが、50 を超えるプレイヤーは攻撃できません。<br>
    <br>
    この弱いプレイヤーが攻撃できるプレイヤーは次のように計算されます:<br>
    <br>
    10 / 5 = 2<br>
    <br>
    つまり、2 ポイントを超えるプレイヤーなら誰でも攻撃できます。',
    // Misc
    'se_save_parameters' => '変更を保存',
    'se_configuration_title' => '設定',
    'se_server_planet_parameters' => '新規惑星の設定',
    'se_all_ok_message' => '変更を保存しました！',
];
