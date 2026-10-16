<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Libraries\Alliance\Ranks;
use App\Models\Alliance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AllianceRankService
{
    /**
     * Delete a rank of an alliance and save the ranks. Its members become
     * newcomers, and the members of the following ranks keep their rank,
     * which moved up one id.
     *
     * Bugfix: the members' rank ids were left as they were, so after a
     * deletion every member of a following rank had the next rank's name
     * and rights (the last one the newcomer's), and members of the deleted rank
     * got the rank after it.
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function deleteRank(int $allianceId, Ranks $ranks, int $rankId): void
    {
        if (!$ranks->deleteRankById($rankId)) {
            return;
        }

        DB::transaction(function () use ($allianceId, $ranks, $rankId): void {
            User::where('ally_id', $allianceId)
                ->where('ally_rank_id', $rankId)
                ->update(['ally_rank_id' => Ranks::NEWCOMER]);

            User::where('ally_id', $allianceId)
                ->where('ally_rank_id', '>', $rankId)
                ->decrement('ally_rank_id');

            Alliance::where('alliance_id', $allianceId)
                ->update(['alliance_ranks' => $ranks->getAllRanksAsJsonString()]);
        });
    }
}
