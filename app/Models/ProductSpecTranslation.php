<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSpecTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['locale', 'value'];

    public function productSpec(): BelongsTo
    {
        return $this->belongsTo(ProductSpec::class, 'product_spec_id');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'locale', 'code');
    }
}
