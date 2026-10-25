<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PowerUpUse extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'second' => 'integer', 'ends_second' => 'integer'];
    }
}
