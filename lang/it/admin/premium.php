<?php

declare(strict_types=1);

return [
    'pr_title' => 'Impostazioni premium',
    'pr_sub_title' => 'Tutto ciò che riguarda la materia oscura si gestisce qui, dagli ufficiali ai mercanti.',
    'pr_general' => 'Generale',
    'pr_pay_url' => 'URL di pagamento',
    'pr_registration_dark_matter' => 'Materia oscura iniziale',
    'pr_trader' => 'Mercato delle risorse - Scambia risorse',
    'pr_trader_price' => 'Prezzo per chiamare il mercante (materia oscura)',
    'pr_merchant_base_min_exchange_rate' => 'Tasso di cambio minimo',
    'pr_merchant_base_max_exchange_rate' => 'Tasso di cambio massimo',
    'pr_merchant_metal_multiplier' => 'Tasso base del metallo',
    'pr_merchant_crystal_multiplier' => 'Tasso base dei cristalli',
    'pr_merchant_deuterium_multiplier' => 'Tasso base del deuterio',
    'pr_scrap' => 'Rottamaio',
    'pr_scrap_base_rate' => 'Quota del prezzo di costruzione (%)',
    'pr_scrap_max_rate' => 'Quota massima dopo la trattativa (%)',
    'pr_scrap_bargain_price' => 'Prezzo della prima trattativa (ogni successiva costa altrettanto in più, al massimo cinque volte tanto)',
    'pr_merchant_explanation' => 'I tassi del mercante seguono un rapporto 3/2/1 (valori predefiniti), dato dai 3 tassi base. A ogni chiamata del mercante il prezzo oscilla tra 0,7 e 1. Per esempio, se vendi metallo, il prezzo base è 3, mentre cristalli e deuterio vengono calcolati con un numero casuale tra 0,7 (predefinito) e 1 (predefinito).<br><br>Esempio:<br>random(0.7,1)=0.88;<br>prezzo del metallo = 3 <br>prezzo dei cristalli = 2 * 0.88 (3 metallo = 1,76 cristalli)<br>prezzo del deuterio = 1 * 0.88 (3 metallo = 0,88 deuterio)<br><br>Lo stesso vale per tutte e 3 le risorse; la risorsa venduta resta sempre la costante.',
    'pr_save_changes' => 'Salva modifiche',
    'pr_all_ok_message' => 'Modifiche salvate!',
];
