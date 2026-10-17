<?php

declare(strict_types=1);

return [
    'pr_title' => 'プレミアム設定',
    'pr_sub_title' => 'オフィサーから商人まで、ダークマターに関するすべてをここで管理できます。',
    'pr_general' => '一般',
    'pr_pay_url' => '支払い URL',
    'pr_registration_dark_matter' => '初期ダークマター',
    'pr_trader' => '資源市場 - 資源の交換',
    'pr_trader_price' => '商人を呼ぶ料金（ダークマター）',
    'pr_merchant_base_min_exchange_rate' => '最小交換レート',
    'pr_merchant_base_max_exchange_rate' => '最大交換レート',
    'pr_merchant_metal_multiplier' => 'メタルの基本レート',
    'pr_merchant_crystal_multiplier' => 'クリスタルの基本レート',
    'pr_merchant_deuterium_multiplier' => 'デューテリウムの基本レート',
    'pr_scrap' => 'スクラップ商人',
    'pr_scrap_base_rate' => '建造価格に対する割合 (%)',
    'pr_scrap_max_rate' => '値切り後の最大割合 (%)',
    'pr_scrap_bargain_price' => '最初の値切りの料金（以降は毎回その分ずつ高くなり、最大5倍まで）',
    'pr_merchant_explanation' => '商人のレートは 3 つの基本レートにより 3/2/1 の比率（デフォルト値）で変動します。商人を呼ぶたびに、価格は 0.7% から 1% の間で変動します。例えばメタルを売る場合、基本価格は 3 ですが、クリスタルとデューテリウムは 0.7（デフォルト）から 1（デフォルト）の間の乱数に基づいて計算されます。<br><br>例:<br>random(0.7,1)=0.88;<br>メタル価格 = 3 <br>クリスタル価格 = 2 * 0.88 (メタル 3 = クリスタル 1.76)<br>デューテリウム価格 = 1 * 0.88 (メタル 3 = デューテリウム 0.88)<br><br>これは 3 つの資源すべてで同じように繰り返され、売る資源が常に基準となります。',
    'pr_save_changes' => '変更を保存',
    'pr_all_ok_message' => '変更を保存しました！',
];
