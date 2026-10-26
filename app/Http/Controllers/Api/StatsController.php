<?php

namespace App\Http\Controllers\Api;

use App\Game\Stats;
use App\Http\Controllers\Controller;
use App\Models\RoundResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StatsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        Stats::backfill($userId);
        $query = RoundResult::where('user_id', $userId);
        $summary = (clone $query)->selectRaw("COUNT(*) AS total,
            SUM(CASE WHEN outcome <> 'left' THEN 1 ELSE 0 END) AS played,
            SUM(CASE WHEN won = 1 THEN 1 ELSE 0 END) AS won,
            SUM(CASE WHEN team = 'runner' AND outcome = 'survived' THEN 1 ELSE 0 END) AS never_caught,
            MAX(CASE WHEN team = 'runner' AND outcome <> 'left' THEN seconds ELSE 0 END) AS longest,
            MAX(metres) AS farthest,
            SUM(CASE WHEN team = 'runner' AND outcome <> 'left' THEN 1 ELSE 0 END) AS runner_played,
            SUM(CASE WHEN team = 'runner' AND won = 1 THEN 1 ELSE 0 END) AS runner_won,
            SUM(CASE WHEN team = 'hunter' AND outcome <> 'left' THEN 1 ELSE 0 END) AS hunter_played,
            SUM(CASE WHEN team = 'hunter' AND won = 1 THEN 1 ELSE 0 END) AS hunter_won")->toBase()->first();

        return response()->json([
            'summary' => [
                'total' => (int) $summary->total, 'played' => (int) $summary->played, 'won' => (int) $summary->won,
                'neverCaught' => (int) $summary->never_caught, 'longest' => (int) $summary->longest, 'farthest' => (int) $summary->farthest,
                'runnerPlayed' => (int) $summary->runner_played, 'runnerWon' => (int) $summary->runner_won,
                'hunterPlayed' => (int) $summary->hunter_played, 'hunterWon' => (int) $summary->hunter_won,
            ],
            'recent' => $query->orderByDesc('played_at')->orderByDesc('id')->limit(12)->get()->map(fn (RoundResult $r) => [
                'id' => $r->source_id, 'date' => $r->played_at->toIso8601ZuluString(), 'source' => $r->source,
                'team' => $r->team, 'outcome' => $r->outcome, 'seconds' => $r->seconds, 'metres' => $r->metres, 'won' => $r->won,
            ]),
        ]);
    }

    /** Demo results are explicitly self-reported, account-scoped and idempotent. */
    public function storeDemo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'date' => ['required', 'date'],
            'team' => ['required', 'in:runner,hunter'],
            'outcome' => ['required', 'in:survived,tagged,huntersWin,runnersWin,left'],
            'seconds' => ['required', 'integer', 'between:0,10800'],
            'metres' => ['required', 'integer', 'between:0,1000000'],
        ]);
        $won = $data['outcome'] !== 'left' && (
            ($data['team'] === 'runner' && in_array($data['outcome'], ['survived', 'runnersWin'], true))
            || ($data['team'] === 'hunter' && $data['outcome'] === 'huntersWin')
        );
        RoundResult::firstOrCreate(['user_id' => $request->user()->id, 'source_id' => 'demo:'.$data['id']], [
            'source' => 'demo', 'team' => $data['team'], 'outcome' => $data['outcome'], 'seconds' => $data['seconds'],
            'metres' => $data['metres'], 'won' => $won, 'played_at' => Carbon::parse($data['date'])->min(now()),
        ]);

        return response()->json(['saved' => true]);
    }
}
