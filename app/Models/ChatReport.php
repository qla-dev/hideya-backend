<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A player's report of a chat message; read by admins in the database only. */
class ChatReport extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];
}
