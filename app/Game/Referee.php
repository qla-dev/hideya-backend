<?php

namespace App\Game;

use App\Models\Player;
use App\Models\Room;
use App\Models\RoomPing;

/**
 * Applies the host's schedule without a cron: every request from a room calls
 * tick() on the locked room, which announces whatever has fallen due since the
 * last call (head start over, pings, zone shrinks) and ends the round when time
 * runs out or every runner is tagged.
 */
final class Referee
{
    public static function tick(Room $room): void
    {
        if ($room->status !== 'playing') {
            return;
        }
        $room->load('players');
        $t = $room->elapsed();
        $fired = $room->fired ?? [];
        $fire = function (string $id) use (&$fired): bool {
            if (in_array($id, $fired, true)) {
                return false;
            }
            $fired[] = $id;

            return true;
        };

        if ($t >= $room->headStartSeconds() && $fire('head')) {
            self::announce($room, 'runner', 'chat.event.headOverRunners');
            self::announce($room, 'hunter', 'chat.event.headOverHunters');
        }
        foreach (Rules::pingSeconds($room->rules) as $second) {
            if ($second <= $t && $second < $room->roundSeconds() && $fire("ping-{$second}")) {
                self::ping($room, $second);
            }
        }
        foreach (Rules::shrinkSeconds($room->rules) as $second) {
            $size = Rules::zoneAt($room->rules, $second);
            if ($second - 60 <= $t && $fire("warn-{$second}")) {
                self::announce($room, null, 'chat.event.shrinkSoon', ['size' => $size]);
            }
            if ($second <= $t && $fire("shrink-{$second}")) {
                self::announce($room, null, 'chat.event.shrinking', ['size' => $size]);
            }
        }
        $room->fired = $fired;
        $room->save();

        $runnersLeft = $room->players->filter(fn (Player $p) => $p->team === 'runner' && ! $p->isOut())->count();
        if ($runnersLeft === 0) {
            self::end($room, 'huntersWin');
        } elseif ($t >= $room->roundSeconds()) {
            self::end($room, 'runnersWin');
        }
    }

    public static function end(Room $room, string $outcome): void
    {
        $room->forceFill(['status' => 'ended', 'ended_at' => now(), 'outcome' => $outcome])->save();
        $room->bump();
        $fresh = $room->fresh('players');
        Realtime::room($fresh, 'ended');
        Push::toPlayers($fresh, null, 'Round over', 'Open Hideya to see how it went.', ['code' => $room->code, 'event' => 'ended']);
    }

    /** Hunters get the untagged runners' last known positions; runners are told they were pinged. */
    private static function ping(Room $room, int $second): void
    {
        $positions = $room->players
            ->filter(fn (Player $p) => $p->team === 'runner' && ! $p->isOut() && $p->lat !== null)
            ->map(fn (Player $p) => ['id' => $p->id, 'lat' => $p->lat, 'lng' => $p->lng])
            ->values()->all();
        $ping = RoomPing::create(['room_id' => $room->id, 'second' => $second, 'positions' => $positions, 'created_at' => now()]);
        Realtime::team($room, 'hunter', [
            'type' => 'ping',
            'second' => $second,
            'positions' => array_map(fn ($p) => $p + ['live' => false, 'at' => $ping->created_at->toIso8601ZuluString('millisecond'), 'second' => $second], $positions),
        ]);
        self::announce($room, 'runner', 'chat.event.pingRunners');
        self::announce($room, 'hunter', 'chat.event.pingHunters');
    }

    public static function announce(Room $room, ?string $team, string $key, array $values = []): void
    {
        $message = $room->messages()->create(['team' => $team, 'kind' => 'event', 'key' => $key, 'values' => $values ?: null, 'created_at' => now()]);
        foreach ($team ? [$team] : ['hunter', 'runner'] as $to) {
            Realtime::team($room, $to, ['type' => 'chat', 'message' => Presenter::message($message, $room)]);
        }
        Push::forEvent($room, $team, $key);
    }
}
