<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomPing extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['positions' => 'array', 'created_at' => 'datetime'];
    }
}
