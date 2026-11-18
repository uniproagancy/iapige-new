<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcurementTask extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'acknowledged_at' => 'datetime'];
    }
}
