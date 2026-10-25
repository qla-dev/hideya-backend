<?php

namespace App\Game;

use App\Models\Player;
use App\Models\RadioMessage;
use App\Models\Room;
use Illuminate\Support\Carbon;

final class Presenter
{
    private static function time(?Carbon $time): ?string
    {
        return $time?->toIso8601ZuluString('millisecond');
    }

    /** What everyone in the room may see: roster, rules and timing. Never positions. */
    public static function publicState(Room $room): array
    {
        $room->loadMissing('players');
        $started = $room->started_at;

        return [
            'code' => $room->code,
            'status' => $room->status,
            'rules' => $room->rules,
            'center' => $room->center_lat === null ? null : ['latitude' => $room->center_lat, 'longitude' => $room->center_lng],
            'startedAt' => self::time($started),
            'headStartEndsAt' => self::time($started?->copy()->addSeconds($room->headStartSeconds())),
            'endsAt' => self::time($started?->copy()->addSeconds($room->roundSeconds())),
            'endedAt' => self::time($room->ended_at),
            'outcome' => $room->outcome,
            'revision' => $room->revision,
            'serverTime' => self::time(now()),
            'players' => $room->players->sortBy('id')->values()->map(fn (Player $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'host' => $p->is_host,
                'pick' => $p->pick,
                'team' => $p->team,
                'ready' => $p->ready,
                'tagged' => $p->tagged_at !== null,
                'left' => $p->left_at !== null,
            ])->all(),
        ];
    }

    /** The bootstrap for one player: public state plus what only they may see. */
    public static function viewerState(Room $room, Player $viewer): array
    {
        return [
            'room' => self::publicState($room),
            'you' => ['id' => $viewer->id, 'team' => $viewer->team, 'host' => $viewer->is_host],
            'positions' => self::positionsFor($room, $viewer),
            'radio' => self::radioFor($room, $viewer),
            'realtime' => Realtime::clientConfig($room, $viewer),
        ];
    }

    /**
     * Teammates are live. Hunters also see untagged runners where they were at
     * the last ping. Runners never receive hunter positions.
     */
    public static function positionsFor(Room $room, Player $viewer): array
    {
        if (! $viewer->team) {
            return [];
        }
        $room->loadMissing('players');
        $positions = $room->players
            ->filter(fn (Player $p) => $p->team === $viewer->team && $p->isActive() && $p->lat !== null)
            ->map(fn (Player $p) => self::position($p))
            ->values()->all();

        if ($viewer->team === 'hunter' && ($ping = $room->pings()->orderByDesc('second')->first())) {
            $tagged = $room->players->whereNotNull('tagged_at')->pluck('id')->all();
            foreach ($ping->positions as $runner) {
                if (! in_array($runner['id'], $tagged, true)) {
                    $positions[] = $runner + ['live' => false, 'at' => self::time($ping->created_at), 'second' => $ping->second];
                }
            }
        }

        return $positions;
    }

    public static function position(Player $player): array
    {
        return ['id' => $player->id, 'lat' => $player->lat, 'lng' => $player->lng, 'live' => true, 'at' => self::time($player->located_at)];
    }

    public static function radioFor(Room $room, Player $viewer): array
    {
        return $room->messages()
            ->where(fn ($q) => $q->whereNull('team')->orWhere('team', $viewer->team))
            ->orderByDesc('id')->limit(100)->get()->reverse()->values()
            ->map(fn (RadioMessage $m) => self::message($m, $room))->all();
    }

    public static function message(RadioMessage $message, Room $room): array
    {
        return [
            'id' => $message->id,
            'kind' => $message->kind,
            'playerId' => $message->player_id,
            'name' => $message->player_id ? $room->players->firstWhere('id', $message->player_id)?->name : null,
            'text' => $message->text,
            'key' => $message->key,
            'values' => $message->values,
            'team' => $message->team,
            'at' => self::time($message->created_at),
            'second' => $room->started_at ? (int) floor($room->started_at->diffInMilliseconds($message->created_at) / 1000) : null,
        ];
    }
}
