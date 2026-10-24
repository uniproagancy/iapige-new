<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/*
 | Which categories have no picture of their own.
 |
 | Only a root category's picture is ever drawn -- Catalog::buildTree() hands
 | one over at the first level and nothing below it -- so the list says which
 | of the gaps a shopper can actually see, and which are only in the table.
 */
class CategoryImages extends Command
{
    protected $signature = 'categories:images
        {--all : list the ones that do have a picture as well}
        {--visible : only the gaps a shopper can see}
        {--missing-file : also check that the uploaded file is really there}';

    protected $description = 'List the categories with no picture uploaded';

    public function handle(): int
    {
        $locale = Language::defaultCode();

        $categories = Category::withTranslation($locale)->orderBy('sort_order')->get();

        if ($categories->isEmpty()) {
            $this->warn('There are no categories at all.');

            return self::SUCCESS;
        }

        $byParent = $categories->groupBy('parent_id');
        $counts = $this->counts();

        $rows = [];
        $seen = 0;

        $walk = function (?int $parentId, int $depth, string $prefix) use (
            &$walk, $byParent, $counts, $locale, &$rows, &$seen
        ): void {
            foreach ($byParent[$parentId] ?? [] as $category) {
                $name = $category->translate($locale, false)?->name ?: '#'.$category->id;
                $path = $prefix === '' ? $name : $prefix.' › '.$name;

                $has = $this->hasPicture($category);
                $seen++;

                /* A root category is the only one whose picture is drawn. */
                $shown = $depth === 1;

                if ($has ? $this->option('all') : true) {
                    $rows[] = [
                        'id' => $category->id,
                        'depth' => $depth,
                        'path' => $path,
                        'has' => $has,
                        'shown' => $shown,
                        'active' => (bool) $category->is_active,
                        'menu' => (bool) $category->show_in_menu,
                        'home' => (bool) $category->show_on_home,
                        'products' => $counts[$category->id] ?? 0,
                        'image' => (string) $category->image,
                    ];
                }

                $walk($category->id, $depth + 1, $path);
            }
        };

        $walk(null, 1, '');

        if ($this->option('visible')) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => ! $row['has'] && $row['shown'] && $row['active'],
            ));
        }

        $missing = array_values(array_filter($rows, fn (array $row) => ! $row['has']));

        $this->table(
            ['id', 'დონე', 'კატეგორია', 'სურათი', 'ჩანს?', 'აქტიური', 'მენიუ', 'მთავარი', 'პროდუქტი'],
            array_map(fn (array $row) => [
                $row['id'],
                $row['depth'],
                /* The tree can run deep; the table stays readable. */
                mb_strimwidth($row['path'], 0, 58, '…'),
                $row['has'] ? 'არის' : '—',
                $row['shown'] ? 'კი' : 'არა',
                $row['active'] ? 'კი' : 'არა',
                $row['menu'] ? 'კი' : 'არა',
                $row['home'] ? 'კი' : 'არა',
                $row['products'],
            ], $rows),
        );

        $visible = array_values(array_filter(
            $missing,
            fn (array $row) => $row['shown'] && $row['active'],
        ));

        $withProducts = array_values(array_filter(
            $visible,
            fn (array $row) => $row['products'] > 0,
        ));

        $this->newLine();
        $this->line('  კატეგორია სულ:                '.$seen);
        $this->line('  სურათის გარეშე:              '.count($missing));
        $this->line('  მათგან საიტზე ჩანს:          '.count($visible).'  (აქტიური, პირველი დონე)');
        $this->line('  მათგან პროდუქტითაც:          '.count($withProducts));

        if ($this->option('missing-file')) {
            $this->broken($rows);
        }

        $this->newLine();
        $this->line('  სურათი მხოლოდ პირველი დონეზე ჩანს. ქვემოთ მდგომს თუ ატვირთავ,');
        $this->line('  საიტი მას არსად არ გამოიტანს.');

        if ($visible) {
            /* Busiest first -- that is the order worth working in. */
            usort($withProducts, fn (array $a, array $b) => $b['products'] <=> $a['products']);

            $this->newLine();
            $this->info('ასატვირთი, რიგით:');

            foreach ($withProducts as $row) {
                $this->line('  #'.$row['id'].'  '.$row['path'].'   ('.$row['products'].' პროდუქტი)');
            }
        }

        return self::SUCCESS;
    }

    protected function hasPicture(Category $category): bool
    {
        return trim((string) $category->image) !== '';
    }

    /*
     | An uploaded picture whose file has since gone shows the same empty box as
     | no picture at all, so it is worth asking about separately.
     */
    protected function broken(array $rows): void
    {
        $gone = [];

        foreach ($rows as $row) {
            if (! $row['has'] || str_starts_with($row['image'], 'http')) {
                continue;
            }

            $path = ltrim($row['image'], '/');

            if (! Storage::disk('public')->exists($path)) {
                $gone[] = $row;
            }
        }

        $this->newLine();

        if (! $gone) {
            $this->info('ყველა ატვირთული ფაილი ადგილზეა.');

            return;
        }

        $this->error('ატვირთულია, მაგრამ ფაილი აღარ არის: '.count($gone));

        foreach ($gone as $row) {
            $this->line('  #'.$row['id'].'  '.$row['path'].'   ('.$row['image'].')');
        }
    }

    protected function counts(): array
    {
        return Product::query()
            ->selectRaw('category_id, count(*) as n')
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->pluck('n', 'category_id')
            ->all();
    }
}
