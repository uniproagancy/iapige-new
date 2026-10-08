<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallbackRequest extends Model
{
    public const NEW = 'new';

    public const CALLED = 'called';

    public const CLOSED = 'closed';

    protected $fillable = [
        'user_id', 'product_id', 'name', 'phone', 'comment',
        'status', 'note', 'handled_by', 'handled_at', 'page',
    ];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::NEW;
    }
}
