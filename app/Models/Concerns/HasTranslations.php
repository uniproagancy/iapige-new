<?php

namespace App\Models\Concerns;

use App\Models\Language;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Translation-table pattern: Product ↔ ProductTranslation (product_id, locale, …).
 *
 * On the model:
 *     use HasTranslations;
 *     protected array $translatable = ['name', 'slug'];
 *
 * The translation model defaults to "{Model}Translation"; override with
 *     protected string $translationModel = SomethingElse::class;
 *
 * Reading:  $product->name  → current locale; an empty field falls back to the default language
 *           $product->translate('en')?->name     (the raw row, no field fallback)
 * Querying: Product::withTranslation()->…          (eager-loads current + fallback only)
 *           Product::whereTranslation('slug', $slug)->first()
 * Saving:   $product->saveTranslations(['ka' => [...], 'en' => [...]])   (admin forms)
 */
trait HasTranslations
{
    public function translations(): HasMany
    {
        return $this->hasMany($this->translationModelClass(), $this->getForeignKey());
    }

    public function translationModelClass(): string
    {
        return property_exists($this, 'translationModel') ? $this->translationModel : static::class.'Translation';
    }

    public function translatableAttributes(): array
    {
        return $this->translatable ?? [];
    }

    /** The translation row for $locale, or the default language's row when it is missing. */
    public function translate(?string $locale = null, bool $fallback = true): ?Model
    {
        $locale ??= app()->getLocale();
        $rows = $this->translations;

        $row = $rows->firstWhere('locale', $locale);

        if (! $row && $fallback) {
            $row = $rows->firstWhere('locale', Language::defaultCode());
        }

        return $row;
    }

    public function hasTranslation(string $locale): bool
    {
        return $this->translations->contains('locale', $locale);
    }

    /** Languages this record still lacks — the admin's "needs translation" badge. */
    public function missingLocales(): array
    {
        return array_values(array_diff(Language::activeCodes(), $this->translations->pluck('locale')->all()));
    }

    /** Translated attributes read straight off the model: $product->name. */
    public function getAttribute($key)
    {
        if (in_array($key, $this->translatableAttributes(), true)) {
            return $this->translatedValue($key);
        }

        return parent::getAttribute($key);
    }

    /**
     * One field in $locale. Falls back per field, not per row: an English row
     * with an empty summary still shows the Georgian summary.
     */
    public function translatedValue(string $key, ?string $locale = null): mixed
    {
        $value = $this->translate($locale, false)?->getAttribute($key);

        if ($value === null || $value === '') {
            $value = $this->translate(Language::defaultCode(), false)?->getAttribute($key);
        }

        return $value;
    }

    /** Eager-load only what a page renders: the current locale plus the fallback. */
    public function scopeWithTranslation(Builder $query, ?string $locale = null): Builder
    {
        $locales = array_values(array_unique([$locale ?? app()->getLocale(), Language::defaultCode()]));

        return $query->with(['translations' => fn ($q) => $q->whereIn('locale', $locales)]);
    }

    /**
     * Filter by a translated column: ->whereTranslation('slug', 'laptops').
     * Pass $locale = false to match in any language.
     */
    public function scopeWhereTranslation(Builder $query, string $column, mixed $value, string|false|null $locale = null): Builder
    {
        return $query->whereHas('translations', function (Builder $q) use ($column, $value, $locale) {
            $q->where($column, $value);

            if ($locale !== false) {
                $q->where('locale', $locale ?? app()->getLocale());
            }
        });
    }

    /**
     * Upsert translations from an admin form:
     *     ['ka' => ['name' => '…', 'slug' => '…'], 'en' => [...]]
     * A locale whose fields are all empty is skipped, so optional languages stay untranslated.
     */
    public function saveTranslations(array $byLocale): void
    {
        $allowed = array_flip($this->translatableAttributes());

        foreach ($byLocale as $locale => $fields) {
            $fields = array_intersect_key((array) $fields, $allowed);

            if (! array_filter($fields, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }

            $this->translations()->updateOrCreate(['locale' => $locale], $fields);
        }

        $this->unsetRelation('translations');
    }
}
