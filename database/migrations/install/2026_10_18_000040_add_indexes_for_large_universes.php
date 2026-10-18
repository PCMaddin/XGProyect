<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The indexes the game's queries need once a universe has many players.
     * Without them every page view read all planets (the player's planets,
     * the moon of the current planet), all messages (the count of unread
     * ones) and all fleets (the ones due, the ones of the player): with a
     * million players 40 seconds for the overview.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const INDEXES = [
        'planets' => [
            // also the order in which the statistics sum up a player's planets
            'planets_user_index' => ['planet_user_id', 'planet_destroyed', 'planet_type'],
            'planets_coordinates_index' => ['planet_galaxy', 'planet_system', 'planet_planet', 'planet_type'],
        ],
        'messages' => [
            'messages_receiver_read_index' => ['message_receiver', 'message_read'],
            'messages_receiver_time_index' => ['message_receiver', 'message_time'],
            'messages_time_index' => ['message_time'],
        ],
        'fleets' => [
            'fleets_owner_index' => ['fleet_owner'],
            'fleets_target_owner_index' => ['fleet_target_owner'],
            'fleets_arrival_index' => ['fleet_mess', 'fleet_start_time'],
            'fleets_return_index' => ['fleet_end_time'],
            'fleets_start_index' => ['fleet_start_galaxy', 'fleet_start_system', 'fleet_start_planet'],
            'fleets_end_index' => ['fleet_end_galaxy', 'fleet_end_system', 'fleet_end_planet'],
            'fleets_group_index' => ['fleet_group'],
        ],
        'users' => [
            'users_name_index' => ['name'],
            'users_ally_index' => ['ally_id'],
            'users_onlinetime_index' => ['onlinetime'],
            // the operators players can write to
            'users_authlevel_index' => ['authlevel'],
        ],
        'acs_members' => [
            'acs_members_user_index' => ['acs_user_id'],
        ],
        'buddys' => [
            'buddys_sender_index' => ['buddy_sender'],
            'buddys_receiver_index' => ['buddy_receiver'],
        ],
        'espionage_log' => [
            'espionage_log_user_index' => ['user_id', 'spied_at'],
        ],
        // the cheat scan reads the last week, the cleanup deletes the oldest hours
        'activity_hours' => [
            'activity_hours_hour_index' => ['hour'],
        ],
        // the highscore reads one page of a ranking by rank
        'users_statistics' => [
            'users_statistics_total_rank_index' => ['user_statistic_total_rank'],
            'users_statistics_buildings_rank_index' => ['user_statistic_buildings_rank'],
            'users_statistics_technology_rank_index' => ['user_statistic_technology_rank'],
            'users_statistics_military_rank_index' => ['user_statistic_military_rank'],
            'users_statistics_honor_rank_index' => ['user_statistic_honor_rank'],
            'users_statistics_military_built_rank_index' => ['user_statistic_military_built_rank'],
            'users_statistics_military_destroyed_rank_index' => ['user_statistic_military_destroyed_rank'],
            'users_statistics_military_lost_rank_index' => ['user_statistic_military_lost_rank'],
            // the honour titles count the players above or below
            'users_statistics_honor_points_index' => ['user_statistic_honor_points'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $tableName => $indexes) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($tableName, $columns)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($columns, $name) {
                    $table->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $tableName => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasTable($tableName) && Schema::hasIndex($tableName, $name)) {
                    Schema::table($tableName, function (Blueprint $table) use ($name) {
                        $table->dropIndex($name);
                    });
                }
            }
        }
    }
};
