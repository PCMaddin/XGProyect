<?php

declare(strict_types=1);

return [
    'pr_title' => 'Ustawienia premium',
    'pr_sub_title' => 'Tutaj możesz zarządzać wszystkim, co dotyczy Ciemnej Materii – od oficerów po handlarzy.',
    'pr_general' => 'Ogólne',
    'pr_pay_url' => 'URL płatności',
    'pr_registration_dark_matter' => 'Początkowa Ciemna Materia',
    'pr_trader' => 'Rynek surowców - wymiana surowców',
    'pr_trader_price' => 'Cena wezwania handlarza (Ciemna Materia)',
    'pr_merchant_base_min_exchange_rate' => 'Minimalny kurs wymiany',
    'pr_merchant_base_max_exchange_rate' => 'Maksymalny kurs wymiany',
    'pr_merchant_metal_multiplier' => 'Kurs bazowy metalu',
    'pr_merchant_crystal_multiplier' => 'Kurs bazowy kryształu',
    'pr_merchant_deuterium_multiplier' => 'Kurs bazowy deuteru',
    'pr_scrap' => 'Handlarz złomem',
    'pr_scrap_base_rate' => 'Udział w cenie budowy (%)',
    'pr_scrap_max_rate' => 'Najwyższy udział po targowaniu (%)',
    'pr_scrap_bargain_price' => 'Cena pierwszego targowania (każde kolejne kosztuje o tyle więcej, najwyżej pięć razy tyle)',
    'pr_merchant_explanation' => 'Kursy handlarza różnią się w proporcji 3/2/1 (wartości domyślne), wyznaczonej przez 3 kursy bazowe. Przy każdym wezwaniu handlarza cena waha się między 0.7% a 1%. Na przykład przy sprzedaży metalu cena bazowa wynosi 3, a kryształ i deuter są liczone na podstawie losowej liczby między 0.7 (domyślnie) a 1 (domyślnie).<br><br>Przykład:<br>random(0.7,1)=0.88;<br>cena metalu = 3 <br>cena kryształu = 2 * 0.88 (3 metalu = 1.76 kryształu)<br>cena deuteru = 1 * 0.88 (3 metalu = 0.88 deuteru)<br><br>Tak samo dzieje się dla wszystkich 3 surowców, a sprzedawany surowiec jest zawsze stały.',
    'pr_save_changes' => 'Zapisz zmiany',
    'pr_all_ok_message' => 'Zmiany zostały zapisane!',
];
