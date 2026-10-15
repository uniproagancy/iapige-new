<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlideTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['locale', 'dates', 'title', 'sub', 'cta'];

    public function slide(): BelongsTo
    {
        return $this->belongsTo(Slide::class, 'slide_id');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'locale', 'code');
    }
}
