<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One player blocking another from the chat. */
class ChatBlock extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** Players in this room whose messages the viewer has blocked, here or (signed in) in an earlier game. */
    public static function blockedIn(Room $room, Player $viewer): array
    {
        $byPlayer = self::where('blocker_player_id', $viewer->id)->whereNotNull('blocked_player_id')->pluck('blocked_player_id')->all();
        $byUser = $viewer->user_id
            ? self::where('blocker_user_id', $viewer->user_id)->whereNotNull('blocked_user_id')->pluck('blocked_user_id')->all()
            : [];

        return $room->players
            ->filter(fn (Player $p) => $p->id !== $viewer->id && (in_array($p->id, $byPlayer, true) || ($p->user_id && in_array($p->user_id, $byUser, true))))
            ->pluck('id')->values()->all();
    }
}
