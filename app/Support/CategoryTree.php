<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;

class CategoryTree
{
    public const KEY = 'categories.tree';

    protected static ?array $map = null;

    protected static ?array $children = null;

    protected static array $models = [];

    /* id => parent_id for every category, active or not: a hidden parent still
       has to be walked through to reach the root. */
    public static function parents(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        return self::$map = Cache::rememberForever(
            self::KEY,
            fn () => Category::query()->pluck('parent_id', 'id')->map(fn ($p) => $p ? (int) $p : null)->all(),
        );
    }

    public static function children(): array
    {
        if (self::$children !== null) {
            return self::$children;
        }

        $children = [];

        foreach (self::parents() as $id => $parent) {
            $children[$parent ?? 0][] = (int) $id;
        }

        return self::$children = $children;
    }

    public static function ancestorIds(int $id): array
    {
        $parents = self::parents();
        $trail = [];
        $seen = [];

        $node = $parents[$id] ?? null;

        while ($node && ! isset($seen[$node])) {
            $seen[$node] = true;
            array_unshift($trail, $node);
            $node = $parents[$node] ?? null;
        }

        return $trail;
    }

    public static function descendantAndSelfIds(int $id): array
    {
        $children = self::children();
        $ids = [$id];
        $level = [$id];

        while ($level) {
            $next = [];

            foreach ($level as $parent) {
                foreach ($children[$parent] ?? [] as $child) {
                    if (! in_array($child, $ids, true)) {
                        $ids[] = $child;
                        $next[] = $child;
                    }
                }
            }

            $level = $next;
        }

        return $ids;
    }

    /* Breadcrumbs, the sidebar and the schema each ask for the same trail on
       one page, so the models are resolved once per request. */
    public static function ancestorModels(int $id): array
    {
        if (isset(self::$models[$id])) {
            return self::$models[$id];
        }

        $ids = self::ancestorIds($id);

        if (! $ids) {
            return self::$models[$id] = [];
        }

        $byId = Category::whereIn('id', $ids)->withTranslation()->get()->keyBy('id');

        $trail = [];

        foreach ($ids as $ancestor) {
            if ($found = $byId->get($ancestor)) {
                $trail[] = $found;
            }
        }

        return self::$models[$id] = $trail;
    }

    public static function forget(): void
    {
        self::$map = null;
        self::$children = null;
        self::$models = [];

        Cache::forget(self::KEY);
    }
}
