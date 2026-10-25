<?php

namespace App\Game;

use App\Models\Player;
use App\Models\PushToken;
use App\Models\Room;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lock-screen alerts through Expo's push service, for players signed in with
 * an account that registered a device. A push failure never fails the game.
 */
final class Push
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    /** Chat events that are also worth a notification, with their English copy. */
    private const COPY = [
        'chat.event.headOverRunners' => ['Hunters are out.', 'Stay hidden.'],
        'chat.event.headOverHunters' => ['Head start is over.', 'Go get them!'],
        'chat.event.pingRunners' => ['Ping!', 'Your location was just sent to the hunters. Move!'],
        'chat.event.pingHunters' => ['Ping!', 'The runners just lit up on your map.'],
        'chat.event.shrinkSoon' => ['Zone shrinks in 1 min', 'Get inside before it closes.'],
        'chat.event.powerUp.shrinkOther' => ['Zone shrinks in 30 s!', 'A hunter used Shrink now. Get inside.'],
        'chat.event.powerUp.flareOther' => ['Flare!', 'The hunters just saw where you are. Move!'],
        'chat.event.pickupSpawned' => ['A power-up appeared', 'It’s on the map. First one there takes it.'],
    ];

    public static function forEvent(Room $room, ?string $team, string $key): void
    {
        if (isset(self::COPY[$key])) {
            [$title, $body] = self::COPY[$key];
            self::toPlayers($room, $team, $title, $body, ['code' => $room->code, 'event' => $key]);
        }
    }

    public static function toPlayers(Room $room, ?string $team, string $title, string $body, array $data = []): void
    {
        $userIds = $room->players
            ->filter(fn (Player $p) => $p->user_id && $p->isActive() && ($team === null || $p->team === $team))
            ->pluck('user_id')->unique()->all();
        if (! $userIds) {
            return;
        }
        $tokens = PushToken::whereIn('user_id', $userIds)->pluck('token')->all();
        $messages = array_map(fn ($to) => ['to' => $to, 'title' => $title, 'body' => $body, 'sound' => 'default', 'priority' => 'high', 'channelId' => 'game', 'data' => $data], $tokens);
        self::send($messages);
    }

    private static function send(array $messages): void
    {
        foreach (array_chunk($messages, 100) as $chunk) {
            try {
                $request = Http::timeout(4)->acceptJson();
                if ($token = config('services.expo.access_token')) {
                    $request = $request->withToken($token);
                }
                $tickets = $request->post(self::ENDPOINT, $chunk)->json('data') ?? [];
                // Forget devices Expo says are gone.
                foreach ($tickets as $i => $ticket) {
                    if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                        PushToken::where('token', $chunk[$i]['to'])->delete();
                    }
                }
            } catch (Throwable $error) {
                Log::warning('Expo push failed', ['error' => $error->getMessage()]);
            }
        }
    }
}
