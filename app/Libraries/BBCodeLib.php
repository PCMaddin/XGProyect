<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Services\FormatService;

/**
 * Minimal BBCode -> HTML renderer used for alliance texts.
 *
 * The legacy version dispatched each tag through eval() on a string built from
 * the matched content; this one maps every tag to a typed handler instead, so
 * no user input is ever evaluated as PHP.
 *
 * The text is HTML-escaped before the tags are rendered, and tag arguments
 * (colors, fonts, sizes, links, images, emails) are only used when valid.
 * Bugfix (stored XSS): the text was not escaped at all, and arguments could
 * break out of their attribute, e.g. [img]x" onerror="...[/img].
 */
class BBCodeLib
{
    public function __construct(private FormatService $formatService)
    {
    }

    public function bbCode(?string $string = ''): string
    {
        $result = htmlspecialchars(stripslashes($string ?? ''), ENT_QUOTES | ENT_SUBSTITUTE);

        foreach ($this->rules() as [$pattern, $handler]) {
            $replaced = preg_replace_callback($pattern, $handler, $result);
            $result = $replaced ?? $result;
        }

        return $result;
    }

    /**
     * @return list<array{0: string, 1: callable(array<int, string>): string}>
     */
    private function rules(): array
    {
        return [
            ['/\n/', fn (): string => '<br>'],
            ['/\r/', fn (): string => ''],
            ['/\[list\](.*?)\[\/list\]/is', fn (array $matches): string => $this->setList($this->group($matches, 1))],
            ['/\[b\](.*?)\[\/b\]/is', fn (array $matches): string => $this->wrapStyle('font-weight: bold;', $this->group($matches, 1))],
            ['/\[strong\](.*?)\[\/strong\]/is', fn (array $matches): string => $this->wrapStyle('font-weight: bold;', $this->group($matches, 1))],
            ['/\[i\](.*?)\[\/i\]/is', fn (array $matches): string => $this->wrapStyle('font-style: italic;', $this->group($matches, 1))],
            ['/\[u\](.*?)\[\/u\]/is', fn (array $matches): string => $this->wrapStyle('text-decoration: underline;', $this->group($matches, 1))],
            ['/\[s\](.*?)\[\/s\]/is', fn (array $matches): string => $this->wrapStyle('text-decoration: line-through;', $this->group($matches, 1))],
            ['/\[del\](.*?)\[\/del\]/is', fn (array $matches): string => $this->wrapStyle('text-decoration: line-through;', $this->group($matches, 1))],
            ['/\[url=(.*?)\](.*?)\[\/url\]/is', fn (array $matches): string => $this->setUrl($this->group($matches, 1), $this->group($matches, 2))],
            ['/\[email=(.*?)\](.*?)\[\/email\]/is', fn (array $matches): string => $this->setEmail($this->group($matches, 1), $this->group($matches, 2))],
            ['/\[img\](.*?)\[\/img\]/is', fn (array $matches): string => $this->setImage($this->group($matches, 1))],
            ['/\[color=(#?[a-z0-9]+)\](.*?)\[\/color\]/is', fn (array $matches): string => $this->wrapStyle('color:' . $this->group($matches, 1), $this->group($matches, 2))],
            ['/\[font=([a-z0-9 ,\-]+)\](.*?)\[\/font\]/is', fn (array $matches): string => $this->wrapStyle('font-family:' . $this->group($matches, 1), $this->group($matches, 2))],
            ['/\[bg=(#?[a-z0-9]+)\](.*?)\[\/bg\]/is', fn (array $matches): string => $this->wrapStyle('background-color:' . $this->group($matches, 1), $this->group($matches, 2))],
            ['/\[size=([0-9]{1,2})\](.*?)\[\/size\]/is', fn (array $matches): string => $this->wrapStyle('font-size:' . $this->group($matches, 1) . 'px', $this->group($matches, 2))],
            ['/\[coordinates\](.*?):(.*?):(.*?)\[\/coordinates\]/is', fn (array $matches): string => $this->setCoordinates($this->group($matches, 1), $this->group($matches, 2), $this->group($matches, 3))],
        ];
    }

    /**
     * @param  array<int, mixed>  $matches
     */
    private function group(array $matches, int $index): string
    {
        return isset($matches[$index]) && is_string($matches[$index]) ? $matches[$index] : '';
    }

    private function wrapStyle(string $style, string $content): string
    {
        return '<span style="' . $style . '">' . $content . '</span>';
    }

    private function setList(string $string): string
    {
        $out = '';

        foreach (explode('[*]', $string) as $list) {
            if (trim($list) !== '') {
                $out .= '<li>' . trim($list) . '</li>';
            }
        }

        return '<ul>' . $out . '</ul>';
    }

    /**
     * Only http(s) and ftp links (the legacy blocklist let JavaScript: or
     * " javascript:" through); anything else stays text.
     */
    private function setUrl(string $url, string $title): string
    {
        $url = trim($url);

        if (preg_match('#^(https?|ftp)://[^\s"<>]+$#i', $url) !== 1) {
            return $title;
        }

        return $this->formatService->link($url, $title, strip_tags($title));
    }

    private function setEmail(string $mail, string $title): string
    {
        if (str_contains($mail, '<') || filter_var(html_entity_decode($mail, ENT_QUOTES), FILTER_VALIDATE_EMAIL) === false) {
            return $title;
        }

        return '<a href="mailto:' . $mail . '" title="' . $mail . '">' . $title . '</a>';
    }

    /**
     * An http(s) image, or one of the game's images by relative path.
     */
    private function setImage(string $img): string
    {
        $img = trim($img);

        if (preg_match('#^https?://[^\s"<>]+$#i', $img) !== 1) {
            if (preg_match('#^[a-z0-9_\-/]+\.[a-z]+$#i', $img) !== 1) {
                return $img;
            }

            $img = 'assets/images/' . $img;
        }

        return '<img src="' . $img . '" alt="' . $img . '" title="' . $img . '" />';
    }

    private function setCoordinates(string $galaxy, string $system, string $planet): string
    {
        return $this->formatService->prettyCoords((int) $galaxy, (int) $system, (int) $planet);
    }
}
