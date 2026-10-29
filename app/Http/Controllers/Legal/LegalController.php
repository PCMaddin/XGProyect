<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legal;

use App\Libraries\Functions;
use App\Services\Legal\LegalPages;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\App;

/**
 * /legal/{page}: imprint, privacy policy, terms and withdrawal, as the
 * operators wrote them on /operator. Reachable without signing in and
 * without a universe (SelectInstance).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class LegalController extends BaseController
{
    public function __construct(private LegalPages $pages)
    {
    }

    public function __invoke(Request $request, string $page): View
    {
        // the language of the page linking here, else the browser's
        $languages = Functions::getLanguagesList();
        $chosen = $request->query('lang');
        $locale = is_string($chosen) && in_array($chosen, $languages, true) ? $chosen : ($request->getPreferredLanguage($languages) ?? 'en');
        App::setLocale($locale);

        $text = $this->pages->text($page, $locale);

        abort_if($text === null, 404);

        return view('legal.show', [
            'page' => $page,
            'html' => LegalPages::render($text['body']),
            'updated_at' => $text['updated_at'],
            'text_locale' => $text['locale'],
            'pages' => $this->pages->written(),
        ]);
    }
}
