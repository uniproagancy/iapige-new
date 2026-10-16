<?php

namespace App\Models\Concerns;

use App\Models\Language;
use App\Support\Text;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function missingLocales(): array
    {
        return array_values(array_diff(Language::activeCodes(), $this->translations->pluck('locale')->all()));
    }

    public function getAttribute($key)
    {
        if (in_array($key, $this->translatableAttributes(), true)) {
            return $this->translatedValue($key);
        }

        return parent::getAttribute($key);
    }

    public function translatedValue(string $key, ?string $locale = null): mixed
    {
        $value = $this->translate($locale, false)?->getAttribute($key);

        if ($value === null || $value === '') {
            $value = $this->translate(Language::defaultCode(), false)?->getAttribute($key);
        }

        return $value;
    }

    public function scopeWithTranslation(Builder $query, ?string $locale = null): Builder
    {
        $locales = array_values(array_unique([$locale ?? app()->getLocale(), Language::defaultCode()]));

        return $query->with(['translations' => fn ($q) => $q->whereIn('locale', $locales)]);
    }

    public function scopeWhereTranslation(Builder $query, string $column, mixed $value, string|false|null $locale = null): Builder
    {
        return $query->whereHas('translations', function (Builder $q) use ($column, $value, $locale) {
            $q->where($column, $value);

            if ($locale !== false) {
                $q->where('locale', $locale ?? app()->getLocale());
            }
        });
    }

    public function saveTranslations(array $byLocale): void
    {
        $allowed = array_flip($this->translatableAttributes());

        foreach ($byLocale as $locale => $fields) {

            $fields = Text::utf8All(array_intersect_key((array) $fields, $allowed));

            if (! array_filter($fields, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }

            $this->translations()->updateOrCreate(['locale' => $locale], $fields);
        }

        $this->unsetRelation('translations');
    }
}
