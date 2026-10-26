<?php

namespace App\Game;

use App\Models\Player;
use App\Models\Room;
use App\Models\RoundResult;

final class Stats
{
    /** Called inside the locked room transaction; opening Results is not required. */
    public static function recordRoom(Room $room): void
    {
        if ($room->status !== 'ended' || ! $room->started_at || ! $room->ended_at) {
            return;
        }
        foreach ($room->players as $player) {
            self::recordPlayer($room, $player);
        }
    }

    public static function recordPlayer(Room $room, Player $player): void
    {
        if (! $player->user_id || ! $player->team) {
            return;
        }
        $left = $player->left_at && $player->left_at->lte($room->ended_at);
        $outcome = $left || $room->outcome === 'cancelled' ? 'left'
            : ($player->team === 'hunter' ? $room->outcome
                : ($room->outcome === 'runnersWin' && ! $player->tagged_at ? 'survived' : 'tagged'));
        $until = $room->ended_at;
        foreach ([$player->tagged_at, $player->left_at, $room->started_at->copy()->addSeconds($room->roundSeconds())] as $at) {
            if ($at && $at->lt($until)) {
                $until = $at;
            }
        }
        RoundResult::firstOrCreate(['user_id' => $player->user_id, 'source_id' => 'online:'.$player->id], [
            'player_id' => $player->id, 'source' => 'online', 'team' => $player->team, 'outcome' => $outcome,
            'seconds' => max(0, (int) round($room->started_at->diffInMilliseconds($until) / 1000 - $room->headStartSeconds())),
            'metres' => (int) round($player->metres),
            'won' => $outcome === 'survived' || ($player->team === 'hunter' && $outcome === 'huntersWin'),
            'played_at' => $room->ended_at,
        ]);
    }

    /** Recover previously finished rooms too, without accepting client-authored online results. */
    public static function backfill(int $userId): void
    {
        Player::query()->where('players.user_id', $userId)->whereNotNull('team')
            ->whereHas('room', fn ($q) => $q->where('status', 'ended')->whereNotNull('started_at')->whereNotNull('ended_at'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('round_results')->whereColumn('round_results.player_id', 'players.id')->where('round_results.user_id', $userId))
            ->with('room')->each(fn (Player $player) => self::recordPlayer($player->room, $player));
    }
}
