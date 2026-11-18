<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProcurementEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Procurement history is append-only.'));
        static::deleting(fn () => throw new LogicException('Procurement history is append-only.'));
    }
}
