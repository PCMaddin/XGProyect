<?php

declare(strict_types=1);

return [
    'pr_title' => '高级设置',
    'pr_sub_title' => '与暗物质相关的一切都可以在这里管理，从军官到商人。',
    'pr_general' => '常规',
    'pr_pay_url' => '支付 URL',
    'pr_registration_dark_matter' => '初始暗物质',
    'pr_trader' => '资源市场 - 交换资源',
    'pr_trader_price' => '召唤商人的价格（暗物质）',
    'pr_merchant_base_min_exchange_rate' => '最低汇率',
    'pr_merchant_base_max_exchange_rate' => '最高汇率',
    'pr_merchant_metal_multiplier' => '金属基础汇率',
    'pr_merchant_crystal_multiplier' => '晶体基础汇率',
    'pr_merchant_deuterium_multiplier' => '重氢基础汇率',
    'pr_scrap' => '废品商人',
    'pr_scrap_base_rate' => '占建造价格的比例 (%)',
    'pr_scrap_max_rate' => '讨价还价后的最高比例 (%)',
    'pr_scrap_bargain_price' => '首次讨价还价的价格（之后每次都会增加同样的金额，最多为五倍）',
    'pr_merchant_explanation' => '商人的汇率由 3 个基础汇率决定，按 3/2/1 的比例（默认值）变化。每次召唤商人时，价格会在 0.7% 到 1% 之间波动。例如出售金属时，基础价格为 3，而晶体和重氢则根据 0.7（默认）到 1（默认）之间的随机数计算。<br><br>示例:<br>random(0.7,1)=0.88;<br>金属价格 = 3 <br>晶体价格 = 2 * 0.88 (3 金属 = 1.76 晶体)<br>重氢价格 = 1 * 0.88 (3 金属 = 0.88 重氢)<br><br>三种资源都按同样方式计算，被出售的资源始终保持不变。',
    'pr_save_changes' => '保存更改',
    'pr_all_ok_message' => '更改已保存！',
];
