<?php

declare(strict_types=1);

return [
    'pr_title' => 'Configurações premium',
    'pr_sub_title' => 'Tudo o que envolve matéria escura é gerenciado aqui, dos oficiais aos mercadores.',
    'pr_general' => 'Geral',
    'pr_pay_url' => 'URL de pagamento',
    'pr_registration_dark_matter' => 'Matéria escura inicial',
    'pr_trader' => 'Mercado de recursos - Trocar recursos',
    'pr_trader_price' => 'Preço para chamar o mercador (matéria escura)',
    'pr_merchant_base_min_exchange_rate' => 'Taxa de câmbio mínima',
    'pr_merchant_base_max_exchange_rate' => 'Taxa de câmbio máxima',
    'pr_merchant_metal_multiplier' => 'Taxa base do metal',
    'pr_merchant_crystal_multiplier' => 'Taxa base do cristal',
    'pr_merchant_deuterium_multiplier' => 'Taxa base do deutério',
    'pr_scrap' => 'Sucateiro',
    'pr_scrap_base_rate' => 'Parte do preço de construção (%)',
    'pr_scrap_max_rate' => 'Parte máxima após negociar (%)',
    'pr_scrap_bargain_price' => 'Preço da primeira negociação (cada seguinte custa esse valor a mais, no máximo cinco vezes)',
    'pr_merchant_explanation' => 'As taxas do mercador seguem a proporção 3/2/1 (valores padrão), dada pelas 3 taxas base. A cada chamada do mercador, o preço varia entre 0,7 e 1. Por exemplo, ao vender metal, o preço base é 3, mas o cristal e o deutério são calculados com um número aleatório entre 0,7 (padrão) e 1 (padrão).<br><br>Exemplo:<br>random(0.7,1)=0.88;<br>preço do metal = 3 <br>preço do cristal = 2 * 0.88 (3 metal = 1,76 cristal)<br>preço do deutério = 1 * 0.88 (3 metal = 0,88 deutério)<br><br>O mesmo vale para os 3 recursos; o recurso vendido é sempre a constante.',
    'pr_save_changes' => 'Salvar alterações',
    'pr_all_ok_message' => 'Alterações salvas com sucesso!',
];
