<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operator;

use App\Http\Middleware\OperatorLocale;
use App\Models\Operator;
use App\Services\Legal\LegalPages;
use App\Services\Operators\OperatorLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * The texts of the legal pages (imprint, privacy policy, terms,
 * withdrawal), one per language, the same in every universe.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class LegalController extends BaseController
{
    public function __construct(private LegalPages $pages, private OperatorLog $log)
    {
    }

    public function index(): View
    {
        return view('operator.legal', [
            'overview' => $this->pages->overview(),
            'languages' => OperatorLocale::languages(),
        ]);
    }

    public function edit(Request $request, string $page, string $locale): View
    {
        self::language($locale);
        $body = $this->pages->exact($page, $locale);
        $preview = $request->query('preview') === '1' ? $request->session()->get('operator_legal_preview') : null;

        return view('operator.legal_edit', [
            'page' => $page,
            'locale' => $locale,
            'body' => is_string($preview) ? $preview : $body,
            'preview' => is_string($preview) ? LegalPages::render($preview) : ($body === '' ? '' : LegalPages::render($body)),
            'unsaved' => is_string($preview),
        ]);
    }

    public function update(Request $request, string $page, string $locale): RedirectResponse
    {
        self::language($locale);
        $data = $request->validate(['body' => ['nullable', 'string', 'max:' . LegalPages::MAX_LENGTH]]);
        $body = (string) ($data['body'] ?? '');

        if ($request->input('action') === 'preview') {
            return redirect()->route('operator.legal.edit', ['page' => $page, 'locale' => $locale, 'preview' => 1])
                ->with('operator_legal_preview', $body);
        }

        $operator = Auth::guard('operator')->user();

        if (!$operator instanceof Operator) {
            throw new LogicException('Only reached with an operator signed in.');
        }

        $this->pages->save($page, $locale, $body, $operator->email, time());
        $this->log->record($operator, $operator->email, OperatorLog::LEGAL, (string) $request->ip(), $page . '/' . $locale, ['length' => mb_strlen(trim($body))]);

        return redirect()->route('operator.legal.edit', ['page' => $page, 'locale' => $locale])
            ->with('status', __(trim($body) === '' ? 'operator/operator.op_legal_removed' : 'operator/operator.op_legal_saved'));
    }

    private static function language(string $locale): void
    {
        abort_unless(in_array($locale, OperatorLocale::languages(), true), 404);
    }
}
