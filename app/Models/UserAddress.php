<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAddress extends Model
{
    protected $fillable = ['user_id', 'city_id', 'label', 'name', 'phone', 'address', 'note', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(DeliveryCity::class, 'city_id');
    }

    /** One line, the way it reads on an order. */
    public function oneLine(): string
    {
        return collect([$this->city?->name, $this->address, $this->note])
            ->filter()
            ->implode(', ');
    }

    /** Making one default means the others are not. */
    public function makeDefault(): void
    {
        static::where('user_id', $this->user_id)->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }
}
