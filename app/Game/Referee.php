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
        // Shrink now (a hunter power-up) shifts the zone's clock ahead by zone_advance.
        $zoneT = $t + (int) $room->zone_advance;
        foreach (Rules::shrinkSeconds($room->rules) as $second) {
            $size = Rules::zoneAt($room->rules, $second);
            if ($second - 60 <= $zoneT && $fire("warn-{$second}")) {
                self::announce($room, null, 'chat.event.shrinkSoon', ['size' => $size]);
            }
            if ($second <= $zoneT && $fire("shrink-{$second}")) {
                self::announce($room, null, 'chat.event.shrinking', ['size' => $size]);
            }
        }
        $pickupChanged = PowerUps::tickPickups($room, $t, $fire);
        $room->fired = $fired;
        $room->save();
        if ($pickupChanged) {
            $room->bump();
            Realtime::room($room->fresh('players'), 'pickup');
        }

        $runnersLeft = $room->players->filter(fn (Player $p) => $p->team === 'runner' && ! $p->isOut())->count();
        $huntersLeft = $room->players->filter(fn (Player $p) => $p->team === 'hunter' && $p->isActive())->count();
        if ($runnersLeft === 0) {
            self::end($room, 'huntersWin');
        } elseif ($huntersLeft === 0) {
            // The last hunter left: nobody is hunting, so the runners win.
            self::end($room, 'runnersWin');
        } elseif ($t >= $room->roundSeconds()) {
            self::end($room, 'runnersWin');
        }
    }

    public static function end(Room $room, string $outcome): void
    {
        $room->forceFill(['status' => 'ended', 'ended_at' => now(), 'outcome' => $outcome])->save();
        PowerUps::rewardWinners($room->load('players'), $outcome);
        Stats::recordRoom($room);
        $room->bump();
        $fresh = $room->fresh('players');
        Realtime::room($fresh, 'ended');
        Push::toPlayers($fresh, null, 'Round over', 'Open Hideya to see how it went.', ['code' => $room->code, 'event' => 'ended']);
    }

    /**
     * Hunters get the untagged runners' last known positions; runners are told
     * they were pinged. Ghosted runners are left out, and a waiting decoy is
     * shown in its runner's place. A flare is the same, on demand.
     */
    public static function ping(Room $room, int $second, bool $flare = false): void
    {
        $hidden = PowerUps::ghosted($room, $second);
        $decoys = $flare ? [] : PowerUps::takeDecoys($room, $second);
        $runners = $room->players->filter(fn (Player $p) => $p->team === 'runner' && ! $p->isOut());
        $positions = $runners
            ->reject(fn (Player $p) => in_array($p->id, $hidden, true))
            ->filter(fn (Player $p) => $p->lat !== null || isset($decoys[$p->id]))
            ->map(fn (Player $p) => isset($decoys[$p->id])
                ? ['id' => $p->id, 'lat' => $decoys[$p->id]['lat'], 'lng' => $decoys[$p->id]['lng']]
                : ['id' => $p->id, 'lat' => $p->lat, 'lng' => $p->lng])
            ->values()->all();
        // A flare in the same second as a scheduled ping replaces that snapshot.
        $ping = RoomPing::updateOrCreate(['room_id' => $room->id, 'second' => $second], ['positions' => $positions, 'created_at' => now()]);
        Realtime::team($room, 'hunter', [
            'type' => 'ping',
            'second' => $second,
            'positions' => array_map(fn ($p) => $p + ['live' => false, 'at' => $ping->created_at->toIso8601ZuluString('millisecond'), 'second' => $second], $positions),
        ]);
        if (! $flare) {
            self::announce($room, 'runner', 'chat.event.pingRunners');
            self::announce($room, 'hunter', 'chat.event.pingHunters');
        }
        $hiddenCount = $runners->filter(fn (Player $p) => in_array($p->id, $hidden, true))->count();
        if ($hiddenCount > 0) {
            self::announce($room, 'hunter', 'chat.event.runnersHidden', ['count' => $hiddenCount]);
        }
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
