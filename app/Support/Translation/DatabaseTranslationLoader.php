<?php

namespace App\Support\Translation;

use App\Models\UiTranslation;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Arr;

/**
 * Wraps Laravel's file loader: lang/{locale}/{group}.php still loads, and rows
 * from ui_translations override it. Package namespaces stay file-only.
 *
 *     __('cart.add')      → group "cart", key "add"
 *     __('Some text')     → group "*",   key "Some text"  (JSON-style)
 */
class DatabaseTranslationLoader implements Loader
{
    public function __construct(protected Loader $files) {}

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->files->load($locale, $group, $namespace);

        if ($namespace !== null && $namespace !== '*') {
            return $lines;
        }

        $rows = UiTranslation::lines($locale, $group);

        if ($group === '*') {
            return array_replace($lines, $rows);
        }

        return array_replace_recursive($lines, Arr::undot($rows));
    }

    public function addNamespace($namespace, $hint)
    {
        $this->files->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->files->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->files->namespaces();
    }
}
