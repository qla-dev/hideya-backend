<?php

namespace App\Game;

use App\Events\RoomUpdated;
use App\Events\TeamUpdated;
use App\Models\Player;
use App\Models\Room;
use Illuminate\Support\Facades\Log;
use Throwable;

final class Realtime
{
    public static function enabled(): bool
    {
        return config('broadcasting.default') === 'pusher' && config('broadcasting.connections.pusher.key');
    }

    /** Public Pusher settings and this player's channels, for the app to subscribe with. */
    public static function clientConfig(Room $room, Player $player): array
    {
        return [
            'provider' => self::enabled() ? 'pusher' : null,
            'key' => self::enabled() ? config('broadcasting.connections.pusher.key') : null,
            'cluster' => config('broadcasting.connections.pusher.options.cluster'),
            'authEndpoint' => url('/api/realtime/auth'),
            'channels' => array_values(array_filter([
                "private-room.{$room->code}",
                $player->team ? "private-room.{$room->code}.{$player->team}" : null,
            ])),
            'events' => ['room' => 'room.updated', 'team' => 'team.updated'],
            'heartbeatSeconds' => 5,
        ];
    }

    public static function room(Room $room, string $reason): void
    {
        self::send(new RoomUpdated($room->code, ['reason' => $reason, 'revision' => $room->revision, 'room' => Presenter::publicState($room)]));
    }

    public static function team(Room $room, string $team, array $payload): void
    {
        self::send(new TeamUpdated($room->code, $team, $payload + ['serverTime' => now()->toIso8601ZuluString('millisecond')]));
    }

    // A realtime outage must not fail the request: the app re-syncs from the next response.
    private static function send(object $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $error) {
            Log::warning('Realtime broadcast failed', ['event' => $event::class, 'error' => $error->getMessage()]);
        }
    }
}
