<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\LanguagesRequest;
use App\Services\Admin\LanguagesService;
use App\Services\Instances\InstanceRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * The language files edited in the browser. They are the game's code: with
 * several universes they hold the texts of all of them and of /operator,
 * and some are shown as HTML. The admin of one universe must not change
 * them, so the editor is closed then; the operator edits lang/ on the
 * server.
 */
class LanguagesController extends BaseController
{
    public function __construct(
        private readonly LanguagesService $languagesService,
        private readonly InstanceRegistry $registry,
    ) {
    }

    public function index(Request $request): View | RedirectResponse
    {
        abort_if($this->registry->enabled(), 403, (string) __('admin/languages.le_shared_files'));

        $currentFile = $request->string('file')->toString();
        $translations = [];

        if ($currentFile) {
            $translations = $this->languagesService->loadTranslations($currentFile);

            if ($translations === null) {
                return redirect()
                    ->route('admin.languages')
                    ->with('danger', __('admin/languages.le_all_error_reading'));
            }
        }

        return view('admin.languages', [
            'groupedFiles' => $this->languagesService->getGroupedFiles(),
            'currentFile' => $currentFile,
            'translations' => $translations,
        ]);
    }

    public function update(LanguagesRequest $request): RedirectResponse
    {
        abort_if($this->registry->enabled(), 403, (string) __('admin/languages.le_shared_files'));

        /** @var array{file: string, translations: list<array{key: string, value: string}>} $data */
        $data = $request->validated();

        $this->languagesService->saveTranslations($data['file'], $data['translations']);

        session()->flash('success', __('admin/languages.le_all_ok_message'));

        return redirect()->route('admin.languages', ['file' => $data['file']]);
    }
}
