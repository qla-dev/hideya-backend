<?php

namespace App\Game;

use App\Models\Player;
use App\Models\PowerUpUse;
use App\Models\Room;
use App\Models\User;

/**
 * Power-ups: two per team, each usable once per round from the player's own
 * supply (the registered daily one, then bought credits), plus any extra
 * charges picked up on the map. Never during the head start, and only when the
 * host left power-ups on.
 *
 * Runners: ghost (left out of pings until the next shrink, or 4 min with no
 * zone) and decoy (the next ping shows a point they chose). Hunters: shrink
 * (the next zone shrink comes in 30 s) and flare (an extra ping now).
 */
final class PowerUps
{
    public const KINDS = ['ghost' => 'runner', 'decoy' => 'runner', 'shrink' => 'hunter', 'flare' => 'hunter'];

    /** Packs sold through RevenueCat (non-consumable ids are not used). */
    public const PACKS = ['hideya_powerups_5' => 5, 'hideya_powerups_15' => 15, 'hideya_powerups_40' => 40];

    public const GHOST_WITHOUT_ZONE = 240;

    public const SHRINK_WARNING = 30;

    public const DECOY_MIN_METRES = 150;

    public const PICKUP_FIRST = 120;

    public const PICKUP_EVERY = 240;

    public const PICKUP_LIFETIME = 180;

    public const PICKUP_REACH = 30;

    public static function enabled(Room $room): bool
    {
        return (bool) ($room->rules['powerUps'] ?? true);
    }

    /** What this player can do now; also what the app shows. */
    public static function stateFor(Room $room, Player $player): array
    {
        $uses = PowerUpUse::where('room_id', $room->id)->where('player_id', $player->id)->get();
        $user = $player->user_id ? User::find($player->user_id) : null;
        $second = (int) floor($room->elapsed());
        $ghost = $uses->where('kind', 'ghost')->filter(fn (PowerUpUse $u) => $u->ends_second > $second)->max('ends_second');

        return [
            'enabled' => self::enabled($room),
            'used' => $uses->where('source', '!=', 'pickup')->pluck('kind')->unique()->values()->all(),
            'charges' => (int) $player->power_up_charges,
            'daily' => $user ? self::dailyAvailable($user) : null,
            'credits' => $user ? (int) $user->power_up_credits : null,
            'ghostUntil' => $ghost,
            'decoyPending' => $uses->where('kind', 'decoy')->whereNull('ends_second')->isNotEmpty(),
        ];
    }

    public static function dailyAvailable(User $user): bool
    {
        return $user->daily_power_up_on?->toDateString() !== now()->toDateString();
    }

    public static function use(Room $room, Player $me, string $kind, ?array $point): void
    {
        abort_unless(isset(self::KINDS[$kind]), 422, 'Unknown power-up.');
        abort_unless(self::enabled($room), 409, 'Power-ups are off in this game.');
        abort_unless($room->status === 'playing', 409, 'Power-ups work during a round.');
        abort_unless($me->team === self::KINDS[$kind], 403, 'That power-up is for the other team.');
        abort_if($me->isOut(), 403, 'You’re out.');
        $t = $room->elapsed();
        $second = (int) floor($t);
        abort_if($t < $room->headStartSeconds(), 409, 'Power-ups unlock when the head start ends.');

        // Effect checks before anything is spent.
        $data = null;
        $ends = null;
        if ($kind === 'shrink') {
            $next = self::nextShrink($room, $t);
            abort_if($next === null, 409, 'There is no shrink left to bring forward.');
            abort_if($next - ($t + $room->zone_advance) <= self::SHRINK_WARNING, 409, 'The zone is about to shrink anyway.');
            $recent = PowerUpUse::where('room_id', $room->id)->where('kind', 'shrink')->where('second', '>', $second - 300)->exists();
            abort_if($recent, 409, 'Your team used Shrink now in the last 5 minutes.');
        }
        if ($kind === 'decoy') {
            abort_unless($point, 422, 'Choose where the decoy goes.');
            abort_if($room->center_lat !== null && $room->rules['zone']
                && Geo::metres($room->center_lat, $room->center_lng, $point['latitude'], $point['longitude']) > Rules::zoneAt($room->rules, $t + $room->zone_advance) / 2,
                422, 'Put the decoy inside the zone.');
            abort_if($me->lat !== null && Geo::metres($me->lat, $me->lng, $point['latitude'], $point['longitude']) < self::DECOY_MIN_METRES,
                422, 'Put the decoy at least 150 m from you.');
            abort_if(PowerUpUse::where('player_id', $me->id)->where('kind', 'decoy')->whereNull('ends_second')->exists(), 409, 'Your decoy is already waiting for the next ping.');
            $data = ['lat' => $point['latitude'], 'lng' => $point['longitude']];
        }
        if ($kind === 'ghost') {
            abort_if(self::ghosted($room, $second, $me->id), 409, 'You are already hidden.');
            $next = self::nextShrink($room, $t);
            $ends = $next !== null ? (int) ceil($next - $room->zone_advance) : $second + self::GHOST_WITHOUT_ZONE;
        }

        $source = self::spend($room, $me, $kind);
        PowerUpUse::create(['room_id' => $room->id, 'player_id' => $me->id, 'kind' => $kind, 'source' => $source, 'second' => $second, 'ends_second' => $ends, 'data' => $data, 'created_at' => now()]);

        $room->load('players');
        $team = $me->team;
        $other = $team === 'runner' ? 'hunter' : 'runner';
        match ($kind) {
            'ghost' => [
                Referee::announce($room, $team, 'chat.event.powerUp.ghostTeam', ['name' => $me->name]),
                Referee::announce($room, $other, 'chat.event.powerUp.ghostOther'),
            ],
            // The other team must not learn a decoy is coming.
            'decoy' => Referee::announce($room, $team, 'chat.event.powerUp.decoyTeam', ['name' => $me->name]),
            'shrink' => self::bringShrinkForward($room, $t, $me),
            'flare' => [
                Referee::announce($room, $team, 'chat.event.powerUp.flareTeam', ['name' => $me->name]),
                Referee::announce($room, $other, 'chat.event.powerUp.flareOther'),
                Referee::ping($room, $second, flare: true),
            ],
        };
    }

    /** A pickup charge first (it does not count against once per round), then the daily one, then credits. */
    private static function spend(Room $room, Player $me, string $kind): string
    {
        if ($me->power_up_charges > 0) {
            $me->decrement('power_up_charges');

            return 'pickup';
        }
        $usedOwn = PowerUpUse::where('room_id', $room->id)->where('player_id', $me->id)->where('kind', $kind)->where('source', '!=', 'pickup')->exists();
        abort_if($usedOwn, 409, 'You’ve used this one this round. Grab a power-up on the map for another go.');
        $user = $me->user_id ? User::lockForUpdate()->find($me->user_id) : null;
        if ($user && self::dailyAvailable($user)) {
            $user->forceFill(['daily_power_up_on' => now()->toDateString()])->save();

            return 'daily';
        }
        if ($user && $user->power_up_credits > 0) {
            $user->decrement('power_up_credits');

            return 'credit';
        }
        abort(402, $user ? 'No power-ups left. Get a pack, or grab one on the map.' : 'Sign in to get a free power-up every day, or grab one on the map.');
    }

    private static function bringShrinkForward(Room $room, float $t, Player $me): void
    {
        $next = self::nextShrink($room, $t);
        $room->zone_advance += (int) round($next - ($t + $room->zone_advance) - self::SHRINK_WARNING);
        // The regular one-minute warning would now be wrong; this one replaces it.
        $room->fired = array_values(array_unique([...($room->fired ?? []), "warn-{$next}"]));
        $room->save();
        Referee::announce($room, 'hunter', 'chat.event.powerUp.shrinkTeam', ['name' => $me->name]);
        Referee::announce($room, 'runner', 'chat.event.powerUp.shrinkOther', ['size' => Rules::zoneAt($room->rules, $next)]);
    }

    /** The next scheduled shrink, in shifted (zone) seconds, or null. */
    public static function nextShrink(Room $room, float $t): ?int
    {
        foreach (Rules::shrinkSeconds($room->rules) as $at) {
            if ($at > $t + $room->zone_advance) {
                return $at;
            }
        }

        return null;
    }

    /** Runner ids hidden by an active ghost at this second (or whether one runner is). */
    public static function ghosted(Room $room, int $second, ?int $playerId = null): array|bool
    {
        $query = PowerUpUse::where('room_id', $room->id)->where('kind', 'ghost')->where('ends_second', '>', $second);
        if ($playerId !== null) {
            return $query->where('player_id', $playerId)->exists();
        }

        return $query->pluck('player_id')->all();
    }

    /** Decoys waiting for this ping, by runner id; using them up. */
    public static function takeDecoys(Room $room, int $second): array
    {
        $decoys = PowerUpUse::where('room_id', $room->id)->where('kind', 'decoy')->whereNull('ends_second')->get();
        foreach ($decoys as $decoy) {
            $decoy->forceFill(['ends_second' => $second])->save();
        }

        return $decoys->mapWithKeys(fn (PowerUpUse $d) => [$d->player_id => $d->data])->all();
    }

    /** Spawn a pickup on schedule inside the zone, and let it expire. Returns true when the room changed. */
    public static function tickPickups(Room $room, float $t, callable $fire): bool
    {
        if (! self::enabled($room) || $room->center_lat === null) {
            return false;
        }
        $changed = false;
        if ($room->pickup && $t >= $room->pickup['expires']) {
            $room->pickup = null;
            $changed = true;
        }
        $first = $room->headStartSeconds() + self::PICKUP_FIRST;
        if (! $room->pickup && $t >= $first && $t < $room->roundSeconds() - self::PICKUP_FIRST) {
            $n = (int) floor(($t - $first) / self::PICKUP_EVERY);
            if ($fire("pickup-{$n}")) {
                $radius = ($room->rules['zone'] ? Rules::zoneAt($room->rules, $t + $room->zone_advance) / 2 : 300) * 0.7;
                [$lat, $lng] = Geo::randomPoint($room->center_lat, $room->center_lng, $radius);
                $room->pickup = ['id' => $n, 'lat' => $lat, 'lng' => $lng, 'expires' => (int) floor($t) + self::PICKUP_LIFETIME];
                $changed = true;
                Referee::announce($room, null, 'chat.event.pickupSpawned');
            }
        }

        return $changed;
    }

    /** A player within reach of the live pickup takes it. Returns true when they did. */
    public static function collect(Room $room, Player $me): bool
    {
        $pickup = $room->pickup;
        if (! $pickup || $room->status !== 'playing' || ! $me->team || $me->isOut() || $me->lat === null) {
            return false;
        }
        if (Geo::metres($me->lat, $me->lng, $pickup['lat'], $pickup['lng']) > self::PICKUP_REACH) {
            return false;
        }
        $room->pickup = null;
        $room->save();
        $me->increment('power_up_charges');
        Referee::announce($room->load('players'), null, 'chat.event.pickupCollected', ['name' => $me->name]);

        return true;
    }

    /** RevenueCat purchase of a pack: credit it once per event. */
    public static function creditPack(User $user, string $productId): bool
    {
        $amount = self::PACKS[$productId] ?? 0;
        if ($amount > 0) {
            $user->increment('power_up_credits', $amount);
        }

        return $amount > 0;
    }
}
