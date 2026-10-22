<?php

declare(strict_types=1);

namespace App\Services\Legal;

use App\Services\Instances\InstanceRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * The legal pages of the server (base database): one text per page and
 * language, in Markdown, the same in every universe. A page without a text
 * in the visitor's language shows the one written first.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class LegalPages
{
    public const IMPRINT = 'imprint';
    public const PRIVACY = 'privacy';
    public const TERMS = 'terms';
    public const WITHDRAWAL = 'withdrawal';

    /** in the order of the links */
    public const PAGES = [self::IMPRINT, self::PRIVACY, self::TERMS, self::WITHDRAWAL];

    public const MAX_LENGTH = 200000;

    /** the pages with a text, for the links on every page */
    private const CACHE_KEY = 'legal_pages_written';
    private const CACHE_SECONDS = 60;

    /**
     * The text of a page in a language, or in the one written first.
     *
     * @return array{body: string, locale: string, updated_at: int}|null
     */
    public function text(string $page, string $locale): ?array
    {
        if (!in_array($page, self::PAGES, true) || !$this->ready()) {
            return null;
        }

        $row = $this->table()->where('page', $page)
            ->orderByRaw('locale = ? desc', [$locale])
            ->orderBy('id')
            ->first();

        return $row === null ? null : ['body' => (string) $row->body, 'locale' => (string) $row->locale, 'updated_at' => (int) $row->updated_at];
    }

    /**
     * Markdown to HTML; HTML in the text is shown as text, unsafe links are
     * dropped.
     */
    public static function render(string $body): string
    {
        return Str::markdown($body, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /**
     * The pages with a text in any language.
     *
     * @return list<string>
     */
    public function written(): array
    {
        try {
            $written = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->ready()
                ? array_values(array_filter($this->table()->distinct()->pluck('page')->all(), 'is_string'))
                : []);
        } catch (Throwable) {
            // the links are not worth a broken page
            return [];
        }

        return array_values(array_intersect(self::PAGES, $written));
    }

    /**
     * Every text, by page and language, for /operator.
     *
     * @return array<string, array<string, array{updated_at: int, updated_by: string, length: int}>>
     */
    public function overview(): array
    {
        $overview = array_fill_keys(self::PAGES, []);

        foreach ($this->table()->get(['page', 'locale', 'updated_at', 'updated_by', 'body']) as $row) {
            if (isset($overview[$row->page])) {
                $overview[(string) $row->page][(string) $row->locale] = [
                    'updated_at' => (int) $row->updated_at,
                    'updated_by' => (string) $row->updated_by,
                    'length' => mb_strlen((string) $row->body),
                ];
            }
        }

        return $overview;
    }

    /**
     * The text exactly as stored in one language, or ''.
     */
    public function exact(string $page, string $locale): string
    {
        $body = $this->table()->where('page', $page)->where('locale', $locale)->value('body');

        return is_string($body) ? $body : '';
    }

    /**
     * Writes a text; an empty one removes the page in that language.
     */
    public function save(string $page, string $locale, string $body, string $by, int $now): void
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        Cache::forget(self::CACHE_KEY);

        if ($body === '') {
            $this->table()->where('page', $page)->where('locale', $locale)->delete();

            return;
        }

        $this->table()->upsert(
            [['page' => $page, 'locale' => $locale, 'body' => $body, 'updated_by' => $by, 'updated_at' => $now]],
            ['page', 'locale'],
            ['body', 'updated_by', 'updated_at']
        );
    }

    private function ready(): bool
    {
        return Schema::connection(InstanceRegistry::CONNECTION)->hasTable('legal_pages');
    }

    private function table(): Builder
    {
        return DB::connection(InstanceRegistry::CONNECTION)->table('legal_pages');
    }
}
