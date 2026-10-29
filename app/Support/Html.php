<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Text of players (names of players, planets, alliances) for HTML that the
 * legacy pages build as strings.
 */
final class Html
{
    /** characters that end a text, attribute or script string in HTML */
    public const UNSAFE_IN_NAMES = '/[<>"\'&\\\\`\x00-\x1f]/';

    /**
     * A name (player, planet, moon) without the characters that end a text,
     * attribute or script string: the legacy pages put names into HTML
     * without escaping them everywhere. Players could not enter them; an
     * admin could.
     */
    public static function cleanName(string $name): string
    {
        return trim((string) preg_replace(self::UNSAFE_IN_NAMES, '', $name));
    }

    /**
     * Text inside an HTML string that a script inserts as HTML, written in a
     * Blade attribute: onmouseover="return overlib('… {{ Html::scriptText($name) }} …')".
     *
     * The browser decodes the attribute's entities before the script runs,
     * so one escape left the script with the raw text: a quote ended its
     * string, a tag became HTML. Escaped once here for the HTML the tooltip
     * shows, with backslashes doubled for the script's string (\x3c would
     * become "<"), and once more by Blade for the attribute.
     */
    public static function scriptText(string $text): string
    {
        return strtr(e($text), ['\\' => '\\\\', "\n" => ' ', "\r" => ' ']);
    }

    /**
     * Text in a quoted script string of an attribute built in PHP:
     * onclick="return confirm('… ' . Html::attributeScript($name) . ' …')".
     * Escaped for the string first, then for the attribute, whose entities
     * the browser decodes before the script runs.
     */
    public static function attributeScript(string $text): string
    {
        // the backslash first, so the ones added for the quotes stay single
        return e(str_replace(['\\', "'", '"', "\n", "\r"], ['\\\\', "\\'", '\\"', ' ', ' '], $text));
    }
}
