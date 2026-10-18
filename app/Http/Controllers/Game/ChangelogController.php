<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Models\Changelog;
use App\Services\SettingsService;
use App\Services\TimingService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Routing\Controller as BaseController;
use App\Libraries\Functions;

class ChangelogController extends BaseController
{
    public function __construct(
        private SettingsService $settings,
        private TimingService $timingService,
    ) {
    }

    /**
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function __invoke(): View
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Changelog));

        $changes = $this->entries($this->settings->getString('lang'));

        // a language without changelog entries of its own (German, French) shows the
        // English ones
        if ($changes->isEmpty()) {
            $changes = $this->entries('en');
        }

        return view('changelog.view', [
            'gameTitle' => $this->settings->getString('game_name'),
            'changes' => $changes->map(fn ($entry) => [
                'versionNumber' => $entry->changelog_version,
                'description' => nl2br(
                    $this->timingService->formatShortDate($entry->changelog_date->timestamp)
                    . '<br>'
                    . e($entry->changelog_description)
                ),
            ]),
        ]);
    }

    /**
     * @return Collection<int, Changelog>
     */
    private function entries(string $languageCode): Collection
    {
        return Changelog::query()
            ->join('languages', 'languages.id', '=', 'changelog.changelog_lang_id')
            ->where('languages.code', $languageCode)
            ->orderByDesc('changelog_date')
            ->get(['changelog_version', 'changelog_date', 'changelog_description']);
    }
}
