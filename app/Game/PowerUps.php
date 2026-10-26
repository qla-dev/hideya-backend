<?php

namespace App\Game;

use App\Models\Player;
use App\Models\PowerUpUse;
use App\Models\Room;
use App\Models\User;

/**
 * Power-ups: two per team, each usable once per round from the player's own
 * supply (owned by kind: the daily pack and bought packs), plus any extra
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

    /**
     * Hideya Plus (config/purchases.php): the monthly subscription; its daily
     * pack holds one more (~30 extra a month for €5.99, €0.20 each, under the
     * 40-pack's €0.25).
     */
    public const PLUS_DAILY_BONUS = 1;

    public static function plus(User $user): bool
    {
        return $user->plus_until !== null && $user->plus_until->isFuture();
    }

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
            'inventory' => $user ? self::inventory($user) : null,
            'ghostUntil' => $ghost,
            'decoyPending' => $uses->where('kind', 'decoy')->whereNull('ends_second')->isNotEmpty(),
        ];
    }

    /** The free power-up refills at midnight in the game's home time zone. */
    public static function today(): string
    {
        return now(config('services.hideya.day_timezone'))->toDateString();
    }

    public static function nextReset(): string
    {
        return now(config('services.hideya.day_timezone'))->addDay()->startOfDay()->utc()->toIso8601ZuluString();
    }

    /** How many of each kind the account owns. */
    public static function inventory(User $user): array
    {
        return array_map('intval', array_merge(array_fill_keys(array_keys(self::KINDS), 0), $user->power_ups ?? []));
    }

    private static function add(User $user, string $kind, int $amount): void
    {
        $owned = self::inventory($user);
        $owned[$kind] += $amount;
        $user->power_ups = $owned;
    }

    public static function dailyAvailable(User $user): bool
    {
        return $user->daily_power_up_on?->toDateString() !== self::today();
    }

    /**
     * Opening the daily pack: one of the four kinds, usually one of it,
     * sometimes two (rare) or three (legendary). Opening it again the same day
     * returns that same drop without adding anything (an interrupted reveal can
     * finish). Null when today's was spent without a pack (in a round).
     */
    public static function claimDaily(User $user): ?array
    {
        $user = User::lockForUpdate()->find($user->id);
        if (! self::dailyAvailable($user)) {
            $drop = $user->daily_power_up_drop;

            return ($drop['on'] ?? null) === self::today() && isset($drop['kind'])
                ? ['kind' => $drop['kind'], 'amount' => $drop['amount'], 'rarity' => $drop['rarity'], 'inventory' => self::inventory($user), 'repeat' => true]
                : null;
        }
        $roll = random_int(1, 100);
        [$amount, $rarity] = $roll <= 3 ? [3, 'legendary'] : ($roll <= 15 ? [2, 'rare'] : [1, 'common']);
        if (self::plus($user)) {
            $amount += self::PLUS_DAILY_BONUS;
        }
        $kinds = array_keys(self::KINDS);
        $kind = $kinds[random_int(0, count($kinds) - 1)];
        self::add($user, $kind, $amount);
        $user->forceFill([
            'daily_power_up_on' => self::today(),
            'daily_power_up_drop' => ['on' => self::today(), 'kind' => $kind, 'amount' => $amount, 'rarity' => $rarity],
        ])->save();

        return ['kind' => $kind, 'amount' => $amount, 'rarity' => $rarity, 'inventory' => self::inventory($user), 'repeat' => false];
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

    /**
     * A pickup charge first (it does not count against once per round), then
     * one of this kind the player owns; an unopened daily pack is opened as
     * this kind rather than wasted.
     */
    private static function spend(Room $room, Player $me, string $kind): string
    {
        if ($me->power_up_charges > 0) {
            $me->decrement('power_up_charges');

            return 'pickup';
        }
        $usedOwn = PowerUpUse::where('room_id', $room->id)->where('player_id', $me->id)->where('kind', $kind)->where('source', '!=', 'pickup')->exists();
        abort_if($usedOwn, 409, 'You’ve used this one this round. Grab a power-up on the map for another go.');
        $user = $me->user_id ? User::lockForUpdate()->find($me->user_id) : null;
        if ($user && self::inventory($user)[$kind] > 0) {
            self::add($user, $kind, -1);
            $user->save();

            return 'owned';
        }
        if ($user && self::dailyAvailable($user)) {
            $user->forceFill([
                'daily_power_up_on' => self::today(),
                'daily_power_up_drop' => ['on' => self::today(), 'kind' => $kind, 'amount' => 1, 'rarity' => 'common'],
            ])->save();

            return 'daily';
        }
        abort(402, $user ? 'You have none of this one. Get more power-ups, or grab one on the map.' : 'Sign in to get a free power-up every day, or grab one on the map.');
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

    /**
     * Winning gives each signed-in winner one random power-up: the hunters on a
     * clean sweep, the runners who were never tagged when time runs out.
     */
    public static function rewardWinners(Room $room, string $outcome): void
    {
        $winners = $room->players->filter(fn (Player $p) => $p->isActive() && $p->user_id && match ($outcome) {
            'huntersWin' => $p->team === 'hunter',
            'runnersWin' => $p->team === 'runner' && $p->tagged_at === null,
            default => false,
        });
        $kinds = array_keys(self::KINDS);
        foreach ($winners as $winner) {
            $user = User::lockForUpdate()->find($winner->user_id);
            if (! $user) {
                continue;
            }
            $kind = $kinds[random_int(0, count($kinds) - 1)];
            self::add($user, $kind, 1);
            $user->save();
            $winner->forceFill(['reward' => $kind])->save();
        }
    }

    /** A verified pack purchase: a mix of all four kinds (an even share each, the rest at random). */
    public static function creditPack(User $user, string $productId): bool
    {
        $amount = (int) (config('purchases.packs')[$productId] ?? 0);
        if ($amount <= 0) {
            return false;
        }
        $kinds = array_keys(self::KINDS);
        foreach ($kinds as $kind) {
            self::add($user, $kind, intdiv($amount, 4));
        }
        shuffle($kinds);
        foreach (array_slice($kinds, 0, $amount % 4) as $kind) {
            self::add($user, $kind, 1);
        }
        $user->save();

        return true;
    }
}
