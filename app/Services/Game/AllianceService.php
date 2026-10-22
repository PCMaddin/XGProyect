<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\Concerns\PreparesSql;
use Illuminate\Support\Facades\DB;

/**
 * Founding an alliance, applying to one and answering an application: the
 * alliance page does this for players, the bots do it for themselves.
 * Messages and checks of the page's input stay with the page.
 */
class AllianceService
{
    use PreparesSql;

    public function nameTaken(string $name): bool
    {
        return DB::selectOne(
            $this->prepareSql('SELECT `alliance_name` FROM `' . ALLIANCE . '` WHERE `alliance_name` = ?;'),
            [$name]
        ) !== null;
    }

    public function tagTaken(string $tag): bool
    {
        return DB::selectOne(
            $this->prepareSql('SELECT `alliance_tag` FROM `' . ALLIANCE . '` WHERE `alliance_tag` = ?;'),
            [$tag]
        ) !== null;
    }

    /**
     * Found an alliance with the two default ranks (founder, newcomer) and
     * its statistics row; the founder is its first member. New alliances
     * take no applications until the founder opens them. Returns the
     * alliance id.
     */
    public function found(int $founderId, string $name, string $tag, int $now): int
    {
        $ranks = strtr(
            '[{"rank":"Founder","rights":{"1":1,"2":1,"3":1,"4":1,"5":1,"6":1,"7":1,"8":1,"9":1}},'
            . '{"rank":"Newcomer","rights":{"1":0,"2":0,"3":0,"4":0,"5":0,"6":0,"7":0,"8":0,"9":0}}]',
            [
                'Founder' => (string) __('game/alliance.al_founder_rank_text'),
                'Newcomer' => (string) __('game/alliance.al_new_member_rank_text'),
            ]
        );

        return DB::transaction(function () use ($founderId, $name, $tag, $now, $ranks): int {
            // the name and tag are bound; the legacy code interpolated them
            DB::insert(
                $this->prepareSql(
                    'INSERT INTO `' . ALLIANCE . '`
                        SET `alliance_name` = ?, `alliance_tag` = ?, `alliance_owner` = ?,
                            `alliance_register_time` = ?, `alliance_ranks` = ?;'
                ),
                [$name, $tag, $founderId, $now, $ranks]
            );

            $allianceId = (int) DB::getPdo()->lastInsertId();

            DB::insert(
                $this->prepareSql('INSERT INTO `' . ALLIANCE_STATISTICS . '` SET `alliance_statistic_alliance_id` = ?;'),
                [$allianceId]
            );

            DB::update(
                $this->prepareSql('UPDATE `' . USERS . '` SET `ally_id` = ?, `ally_register_time` = ? WHERE `id` = ?;'),
                [$allianceId, $now, $founderId]
            );

            return $allianceId;
        });
    }

    /**
     * Let players apply to the alliance (the setting on the alliance's
     * admin page).
     */
    public function openForApplications(int $allianceId): void
    {
        // the column says whether applications are allowed (1), despite its name
        DB::table('alliance')->where('alliance_id', $allianceId)->update(['alliance_request_notallow' => 1]);
    }

    /**
     * Apply to an alliance; the application waits for an answer.
     */
    public function apply(int $userId, int $allianceId, string $text, int $now): void
    {
        // the text is bound; the legacy code interpolated it
        DB::update(
            $this->prepareSql(
                'UPDATE `' . USERS . '`
                    SET `ally_request` = ?, `ally_request_text` = ?, `ally_register_time` = ?, `ally_rank_id` = 1
                WHERE `id` = ?;'
            ),
            [$allianceId, $text, $now, $userId]
        );
    }

    /**
     * Withdraw the user's application.
     */
    public function withdraw(int $userId): void
    {
        DB::update(
            $this->prepareSql('UPDATE `' . USERS . '` SET `ally_request` = 0 WHERE `id` = ?;'),
            [$userId]
        );
    }

    /**
     * Accept or decline an application. Whether there was one: only an
     * application to this alliance is answered.
     *
     * Bugfix: any user id was answered, so an alliance admin could pull any
     * player into the alliance (accept) or out of theirs (decline set the
     * alliance to none).
     */
    public function answer(int $userId, int $allianceId, bool $accept): bool
    {
        return DB::update(
            $this->prepareSql(
                'UPDATE `' . USERS . "` SET `ally_request_text` = '', `ally_request` = 0, `ally_id` = ?
                WHERE `id` = ? AND `ally_request` = ? AND `ally_id` = 0;"
            ),
            [$accept ? $allianceId : 0, $userId, $allianceId]
        ) === 1;
    }
}
