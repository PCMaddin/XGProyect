<?php

declare(strict_types=1);

return [
    'pr_title' => 'Premium-Einstellungen',
    'pr_sub_title' => 'Hier verwaltest du alles rund um Dunkle Materie, von Offizieren bis zu Händlern.',
    'pr_general' => 'Allgemein',
    'pr_pay_url' => 'Zahlungs-URL',
    'pr_registration_dark_matter' => 'Dunkle Materie zu Beginn',
    'pr_trader' => 'Rohstoffmarkt - Rohstoffe tauschen',
    'pr_trader_price' => 'Preis für den Händlerruf (Dunkle Materie)',
    'pr_merchant_base_min_exchange_rate' => 'Mindest-Tauschkurs',
    'pr_merchant_base_max_exchange_rate' => 'Höchst-Tauschkurs',
    'pr_merchant_metal_multiplier' => 'Basiskurs Metall',
    'pr_merchant_crystal_multiplier' => 'Basiskurs Kristall',
    'pr_merchant_deuterium_multiplier' => 'Basiskurs Deuterium',
    'pr_scrap' => 'Schrotthändler',
    'pr_scrap_base_rate' => 'Anteil am Baupreis (%)',
    'pr_scrap_max_rate' => 'Höchster Anteil nach Feilschen (%)',
    'pr_scrap_bargain_price' => 'Preis des ersten Feilschens (jedes weitere kostet so viel mehr, höchstens das Fünffache)',
    'pr_merchant_explanation' => 'Die Händlerkurse stehen im Verhältnis 3/2/1 (Standardwerte), gegeben durch die drei Basiskurse. Bei jedem Händlerruf schwankt der Preis zwischen 0,7 % und 1 %. Verkauft man zum Beispiel Metall, ist der Basispreis 3, Kristall und Deuterium werden aber mit einer Zufallszahl zwischen 0,7 (Standard) und 1 (Standard) berechnet.<br><br>Beispiel:<br>random(0.7,1)=0.88;<br>Metallpreis = 3 <br>Kristallpreis = 2 * 0.88 (3 Metall = 1,76 Kristall)<br>Deuteriumpreis = 1 * 0.88 (3 Metall = 0,88 Deuterium)<br><br>Das gilt für alle drei Rohstoffe gleichermaßen, der verkaufte Rohstoff ist dabei immer die Konstante.',
    'pr_save_changes' => 'Änderungen speichern',
    'pr_all_ok_message' => 'Änderungen erfolgreich gespeichert!',
];
