<?php

namespace App\Http\Controllers\Api;

use App\Game\Presenter;
use App\Game\Realtime;
use App\Game\Referee;
use App\Game\Rules;
use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Private rooms. Every response carries the room's revision and server time,
 * so the app can apply it over anything older it got from realtime events.
 */
class RoomController extends Controller
{
    private const MAX_PLAYERS = 20;

    private const CODE_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:24'], ...self::centerRules(), ...Rules::validation()]);

        [$room, $player, $token] = DB::transaction(function () use ($data) {
            $room = Room::create([
                'code' => $this->freeCode(),
                'rules' => Rules::merge([], $data['rules'] ?? []),
                'center_lat' => $data['center']['latitude'] ?? null,
                'center_lng' => $data['center']['longitude'] ?? null,
            ]);
            [$player, $token] = $this->addPlayer($room, $data['name'], true);

            return [$room, $player, $token];
        });

        return response()->json(['token' => $token] + Presenter::viewerState($room->fresh(), $player), 201);
    }

    public function join(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:24']]);

        return DB::transaction(function () use ($code, $data) {
            $room = Room::where('code', strtoupper($code))->lockForUpdate()->first();
            abort_unless($room, 404, 'No game with that code.');
            abort_unless($room->status === 'lobby', 409, 'This game has already started.');
            abort_if($room->players()->whereNull('left_at')->count() >= self::MAX_PLAYERS, 409, 'This game is full.');
            [$player, $token] = $this->addPlayer($room, $data['name'], false);
            $room->bump();
            Realtime::room($room->fresh('players'), 'joined');

            return response()->json(['token' => $token] + Presenter::viewerState($room->fresh(), $player), 201);
        });
    }

    public function show(Request $request, string $code): JsonResponse
    {
        return $this->inRoom($request, $code, fn (Room $room, Player $me) => null);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([...self::centerRules(), ...Rules::validation()]);

        return $this->inRoom($request, $code, function (Room $room, Player $me) use ($data) {
            abort_unless($me->is_host, 403, 'Only the host can change the rules.');
            abort_unless($room->status === 'lobby', 409, 'The round has started.');
            $room->rules = Rules::merge($room->rules, $data['rules'] ?? []);
            if (isset($data['center'])) {
                $room->center_lat = $data['center']['latitude'];
                $room->center_lng = $data['center']['longitude'];
            }
            $room->save();

            return 'rules';
        });
    }

    public function me(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:24'],
            'pick' => ['sometimes', 'in:hunter,runner,random'],
            'ready' => ['sometimes', 'boolean'],
        ]);

        return $this->inRoom($request, $code, function (Room $room, Player $me) use ($data) {
            abort_unless($room->status === 'lobby', 409, 'The round has started.');
            $me->fill($data)->save();

            return 'player';
        });
    }

    public function start(Request $request, string $code): JsonResponse
    {
        return $this->inRoom($request, $code, function (Room $room, Player $me) {
            abort_unless($me->is_host, 403, 'Only the host can start the game.');
            abort_unless($room->status === 'lobby', 409, 'The round has already started.');
            $players = $room->players()->whereNull('left_at')->orderBy('id')->get();
            abort_if($players->count() < 2, 422, 'At least two players are needed.');
            foreach (self::assignTeams($players->pluck('pick', 'id')->all()) as $id => $team) {
                $players->firstWhere('id', $id)->forceFill(['team' => $team, 'tagged_at' => null])->save();
            }
            $room->forceFill(['status' => 'playing', 'started_at' => now(), 'fired' => []])->save();

            return 'started';
        });
    }

    public function location(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180']]);

        return $this->inRoom($request, $code, function (Room $room, Player $me) use ($data) {
            $me->forceFill(['lat' => $data['latitude'], 'lng' => $data['longitude'], 'located_at' => now()])->save();
            if ($room->status === 'playing' && $me->team && $me->isActive()) {
                Realtime::team($room, $me->team, ['type' => 'location', 'position' => Presenter::position($me)]);
            }

            return null; // positions are team events; the roster did not change
        });
    }

    public function radio(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:300']]);

        return $this->inRoom($request, $code, function (Room $room, Player $me) use ($data) {
            abort_unless($room->status === 'playing' && $me->team, 409, 'Radio opens when the round starts.');
            abort_if($me->isOut(), 403, 'You’re out, so you can read Radio but not send.');
            $message = $room->messages()->create(['player_id' => $me->id, 'team' => $me->team, 'kind' => 'chat', 'text' => trim($data['text']), 'created_at' => now()]);
            Realtime::team($room, $me->team, ['type' => 'radio', 'message' => Presenter::message($message, $room->load('players'))]);

            return null;
        });
    }

    /** Honour system: a runner confirms they were tagged in person. */
    public function tagged(Request $request, string $code): JsonResponse
    {
        return $this->inRoom($request, $code, function (Room $room, Player $me) {
            abort_unless($room->status === 'playing' && $me->team === 'runner', 409, 'Only a runner in a running round can be tagged.');
            abort_if($me->tagged_at !== null, 409, 'You’re already out.');
            $me->forceFill(['tagged_at' => now()])->save();
            Referee::announce($room->load('players'), null, 'radio.event.tagged', ['name' => $me->name]);
            Referee::tick($room);

            return 'tagged';
        });
    }

    public function end(Request $request, string $code): JsonResponse
    {
        return $this->inRoom($request, $code, function (Room $room, Player $me) {
            abort_unless($me->is_host, 403, 'Only the host can end the round.');
            abort_unless($room->status === 'playing', 409, 'No round is running.');
            Referee::end($room, 'cancelled');

            return null; // end() already broadcast
        });
    }

    public function leave(Request $request, string $code): JsonResponse
    {
        return $this->inRoom($request, $code, function (Room $room, Player $me) {
            $me->forceFill(['left_at' => now(), 'ready' => false])->save();
            if ($me->is_host && ($next = $room->players()->whereNull('left_at')->orderBy('id')->first())) {
                $me->forceFill(['is_host' => false])->save();
                $next->forceFill(['is_host' => true])->save();
            }
            if ($room->status === 'playing') {
                Referee::tick($room);
            }

            return 'left';
        });
    }

    /** Picks are honoured; random players fill hunters up to a third of the room, then runners. */
    public static function assignTeams(array $picks): array
    {
        $teams = array_map(fn ($pick) => $pick === 'random' ? null : $pick, $picks);
        $target = max(1, intdiv(count($picks), 3));
        foreach ($teams as $id => $team) {
            if ($team === null) {
                $teams[$id] = count(array_keys($teams, 'hunter', true)) < $target ? 'hunter' : 'runner';
            }
        }
        // Every round needs at least one of each.
        foreach (['hunter' => 'runner', 'runner' => 'hunter'] as $need => $from) {
            if (! in_array($need, $teams, true)) {
                $donors = array_keys($teams, $from, true);
                $teams[$need === 'hunter' ? $donors[0] : end($donors)] = $need;
            }
        }

        return $teams;
    }

    /**
     * Resolve the caller from their bearer token, lock the room, let the
     * referee catch up, apply the change, and answer with the fresh state.
     * The callback returns a broadcast reason, or null when the public room
     * state did not change.
     */
    private function inRoom(Request $request, string $code, callable $change): JsonResponse
    {
        $token = (string) $request->bearerToken();
        abort_if($token === '', 401, 'Missing player token.');

        return DB::transaction(function () use ($code, $token, $change) {
            $room = Room::where('code', strtoupper($code))->lockForUpdate()->first();
            abort_unless($room, 404, 'No game with that code.');
            $me = $room->players()->where('token_hash', hash('sha256', $token))->first();
            abort_unless($me, 403, 'You are not in this game.');
            Referee::tick($room);
            $reason = $change($room, $me);
            if ($reason) {
                $room->bump();
                Realtime::room($room->fresh('players'), $reason);
            }

            return response()->json(Presenter::viewerState($room->fresh(), $me->fresh()));
        });
    }

    /** @return array{0: Player, 1: string} */
    private function addPlayer(Room $room, string $name, bool $host): array
    {
        $token = Str::random(48);
        $player = $room->players()->create(['name' => trim($name), 'token_hash' => hash('sha256', $token), 'is_host' => $host, 'pick' => 'random']);

        return [$player, $token];
    }

    private function freeCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_CHARS[random_int(0, strlen(self::CODE_CHARS) - 1)];
            }
        } while (Room::where('code', $code)->exists());

        return $code;
    }

    private static function centerRules(): array
    {
        return [
            'center' => ['sometimes', 'array'],
            'center.latitude' => ['required_with:center', 'numeric', 'between:-90,90'],
            'center.longitude' => ['required_with:center', 'numeric', 'between:-180,180'],
        ];
    }
}
