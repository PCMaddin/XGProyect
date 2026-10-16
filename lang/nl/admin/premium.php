<?php

declare(strict_types=1);

return [
    'pr_title' => 'Premium-instellingen',
    'pr_sub_title' => 'Alles rond donkere materie beheer je hier, van officieren tot handelaren.',
    'pr_general' => 'Algemeen',
    'pr_pay_url' => 'Betaal-URL',
    'pr_registration_dark_matter' => 'Donkere materie bij start',
    'pr_trader' => 'Grondstoffenmarkt - Grondstoffen ruilen',
    'pr_trader_price' => 'Prijs om de handelaar te roepen (donkere materie)',
    'pr_merchant_base_min_exchange_rate' => 'Minimale wisselkoers',
    'pr_merchant_base_max_exchange_rate' => 'Maximale wisselkoers',
    'pr_merchant_metal_multiplier' => 'Basiskoers metaal',
    'pr_merchant_crystal_multiplier' => 'Basiskoers kristal',
    'pr_merchant_deuterium_multiplier' => 'Basiskoers deuterium',
    'pr_scrap' => 'Schroothandelaar',
    'pr_scrap_base_rate' => 'Deel van de bouwprijs (%)',
    'pr_scrap_max_rate' => 'Hoogste deel na onderhandelen (%)',
    'pr_scrap_bargain_price' => 'Prijs van de eerste onderhandeling (elke volgende kost zoveel extra, hoogstens vijf keer zoveel)',
    'pr_merchant_explanation' => 'De koersen van de handelaar volgen een verhouding van 3/2/1 (standaardwaarden), bepaald door de 3 basiskoersen. Bij elke oproep schommelt de prijs tussen 0,7 en 1. Verkoop je bijvoorbeeld metaal, dan is de basisprijs 3, maar kristal en deuterium worden berekend met een willekeurig getal tussen 0,7 (standaard) en 1 (standaard).<br><br>Voorbeeld:<br>random(0.7,1)=0.88;<br>metaalprijs = 3 <br>kristalprijs = 2 * 0.88 (3 metaal = 1,76 kristal)<br>deuteriumprijs = 1 * 0.88 (3 metaal = 0,88 deuterium)<br><br>Dit geldt op dezelfde manier voor alle 3 grondstoffen; de verkochte grondstof blijft steeds de constante.',
    'pr_save_changes' => 'Wijzigingen opslaan',
    'pr_all_ok_message' => 'Wijzigingen opgeslagen!',
];
