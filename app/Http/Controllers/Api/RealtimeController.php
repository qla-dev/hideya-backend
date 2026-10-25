<?php

namespace App\Http\Controllers\Api;

use App\Game\Realtime;
use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Pusher private-channel authorisation, by player token rather than a user
 * session. A player may subscribe to their room's channel and to their own
 * team's channel only: hunters can never listen to the runners' positions.
 */
class RealtimeController extends Controller
{
    public function auth(Request $request): JsonResponse
    {
        $data = $request->validate(['socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'], 'channel_name' => ['required', 'string', 'max:64']]);
        abort_unless(Realtime::enabled(), 503, 'Realtime is not configured.');
        $token = (string) $request->bearerToken();
        abort_if($token === '', 401, 'Missing player token.');
        $player = Player::with('room')->where('token_hash', hash('sha256', $token))->first();
        abort_unless($player && $player->isActive(), 403, 'You are not in a game.');

        $code = $player->room->code;
        $allowed = ["private-room.{$code}"];
        if ($player->team) {
            $allowed[] = "private-room.{$code}.{$player->team}";
        }
        abort_unless(in_array($data['channel_name'], $allowed, true), 403, 'Not your channel.');

        $signature = Broadcast::connection('pusher')->getPusher()->authorizeChannel($data['channel_name'], $data['socket_id']);

        return response()->json(json_decode($signature, true));
    }
}
