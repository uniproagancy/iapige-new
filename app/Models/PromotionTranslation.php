<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The table and the trait were both there; this class was not, so every read
 * of a campaign's title went looking for a model that did not exist.
 */
class PromotionTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['locale', 'title', 'subtitle', 'cta'];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class, 'promotion_id');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'locale', 'code');
    }
}
