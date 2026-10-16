<?php

declare(strict_types=1);

return [
    'pr_title' => 'Paramètres premium',
    'pr_sub_title' => 'Tout ce qui concerne l’antimatière se gère ici, des officiers aux marchands.',
    'pr_general' => 'Général',
    'pr_pay_url' => 'URL de paiement',
    'pr_registration_dark_matter' => 'Antimatière initiale',
    'pr_trader' => 'Marché des ressources - Échanger des ressources',
    'pr_trader_price' => 'Prix pour appeler le marchand (antimatière)',
    'pr_merchant_base_min_exchange_rate' => 'Taux de change minimal',
    'pr_merchant_base_max_exchange_rate' => 'Taux de change maximal',
    'pr_merchant_metal_multiplier' => 'Taux de base du métal',
    'pr_merchant_crystal_multiplier' => 'Taux de base du cristal',
    'pr_merchant_deuterium_multiplier' => 'Taux de base du deutérium',
    'pr_scrap' => 'Ferrailleur',
    'pr_scrap_base_rate' => 'Part du prix de construction (%)',
    'pr_scrap_max_rate' => 'Part maximale après négociation (%)',
    'pr_scrap_bargain_price' => 'Prix de la première négociation (chaque suivante coûte autant en plus, au plus cinq fois ce montant)',
    'pr_merchant_explanation' => 'Les taux du marchand suivent un rapport 3/2/1 (valeurs par défaut), donné par les 3 taux de base. À chaque appel du marchand, le prix varie entre 0,7 et 1. Par exemple, si l’on vend du métal, son prix de base est 3, tandis que ceux du cristal et du deutérium sont calculés avec un nombre aléatoire entre 0,7 (défaut) et 1 (défaut).<br><br>Exemple :<br>random(0.7,1)=0.88;<br>prix du métal = 3 <br>prix du cristal = 2 * 0.88 (3 métal = 1,76 cristal)<br>prix du deutérium = 1 * 0.88 (3 métal = 0,88 deutérium)<br><br>Il en va de même pour les 3 ressources, la ressource vendue restant toujours constante.',
    'pr_save_changes' => 'Enregistrer les modifications',
    'pr_all_ok_message' => 'Modifications enregistrées avec succès !',
];
