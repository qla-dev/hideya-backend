<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RadioMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['values' => 'array', 'created_at' => 'datetime'];
    }
}
