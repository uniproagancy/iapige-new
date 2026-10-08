<?php

namespace App\Livewire\Admin\Promotions;

use App\Models\Product;
use App\Models\Promotion;
use App\Support\Slug;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The deals rail on the front page, filled by hand.
 *
 * Everything behind it already existed — the promotions table, the pivot
 * carrying a price per product, Catalog::deals() reading them — but there was
 * no way to put a product into a campaign, so the rail had nothing to show.
 *
 * One screen: pick or create a campaign, then add products to it. The pivot
 * takes either a fixed price or a percentage off, never both, which is what
 * the migration says and what the storefront reads.
 */
class Index extends Component
{
    #[Url(as: 'promo', except: 0)]
    public int $promotionId = 0;

    /* ---- campaign form ---- */
    public bool $showForm = false;

    public string $code = '';

    public string $title = '';

    public string $slug = '';

    public bool $is_active = true;

    public string $starts_at = '';

    public string $ends_at = '';

    public int $sort_order = 0;

    /* ---- adding products ---- */
    public string $search = '';

    /* ---- the rows, keyed by product id ---- */
    public array $price = [];

    public array $percent = [];

    public array $order = [];

    public function mount(): void
    {
        $this->promotionId = $this->promotionId ?: (int) (Promotion::where('type', 'deal')
            ->orderBy('sort_order')->value('id') ?? 0);

        $this->loadRows();
    }

    public function render()
    {
        $promotion = $this->promotion();

        return view('livewire.admin.promotions.index', [
            'promotions' => Promotion::orderBy('sort_order')->orderBy('code')->get(),
            'promotion' => $promotion,
            'products' => $promotion
                ? $promotion->products()->withTranslation()->with('images')->get()
                : collect(),
            'found' => $this->found($promotion),
        ])->layout('layouts.admin', ['title' => __('admin.promotions')]);
    }

    /* ------------------------------------------------------------------ campaign */

    public function select(int $id): void
    {
        $this->promotionId = $id;
        $this->search = '';
        $this->loadRows();
    }

    public function create(): void
    {
        $this->reset(['code', 'title', 'slug', 'starts_at', 'ends_at', 'sort_order']);
        $this->is_active = true;
        $this->showForm = true;
    }

    public function editPromotion(): void
    {
        if (! $promotion = $this->promotion()) {
            return;
        }

        $this->code = $promotion->code;
        $this->title = (string) $promotion->title;
        $this->slug = (string) $promotion->slug;
        $this->is_active = $promotion->is_active;
        $this->starts_at = $promotion->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->ends_at = $promotion->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->sort_order = $promotion->sort_order;

        $this->showForm = true;
    }

    /**
     * The address follows the title until somebody writes one by hand.
     *
     * Only while the campaign is new: changing the slug of one already running
     * would break every link already printed on a banner or sent out.
     */
    public function updatedTitle(): void
    {
        if (! $this->promotion() && $this->slug === '') {
            $this->slug = Slug::make($this->title);
        }
    }

    public function savePromotion(): void
    {
        $editing = $this->showForm && $this->promotion() && $this->code === $this->promotion()->code;

        $data = $this->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/',
                'unique:promotions,code'.($editing ? ','.$this->promotionId : '')],
            'title' => ['nullable', 'string', 'max:120'],
            /*
             * The address a shopper sees, unique within its language — the
             * column says so, and without this a repeat reached the database
             * as a duplicate-key error instead of a message on the field.
             */
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('promotion_translations', 'slug')
                    ->where('locale', app()->getLocale())
                    ->ignore($this->promotionId, 'promotion_id')],
            'sort_order' => ['integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $promotion = $editing ? $this->promotion() : new Promotion(['type' => 'deal']);

        $promotion->fill([
            'code' => $data['code'],
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'starts_at' => $this->starts_at ? Carbon::parse($this->starts_at) : null,
            'ends_at' => $this->ends_at ? Carbon::parse($this->ends_at) : null,
        ])->save();

        /*
         * A campaign with no slug has no address, so one is made from the
         * title or, failing that, from the code — which is unique already.
         */
        $slug = $this->slug ?: Slug::make($this->title) ?: $promotion->code;

        if ($this->title !== '' || $slug !== '') {
            $promotion->saveTranslations([app()->getLocale() => [
                'title' => $this->title ?: $promotion->code,
                'slug' => $slug,
            ]]);
        }

        $this->slug = $slug;

        $this->promotionId = $promotion->id;
        $this->showForm = false;

        $this->done();
    }

    public function toggleActive(): void
    {
        if ($promotion = $this->promotion()) {
            $promotion->update(['is_active' => ! $promotion->is_active]);
            $this->done();
        }
    }

    /* ------------------------------------------------------------------ products */

    public function add(int $productId): void
    {
        if (! $promotion = $this->promotion()) {
            return;
        }

        $product = Product::findOrFail($productId);

        // syncWithoutDetaching, so adding one twice is not an error
        $promotion->products()->syncWithoutDetaching([$product->id => [
            'sort_order' => (int) $promotion->products()->max('promotion_product.sort_order') + 1,
        ]]);

        $this->search = '';
        $this->loadRows();
        $this->done();
    }

    public function remove(int $productId): void
    {
        if ($promotion = $this->promotion()) {
            $promotion->products()->detach($productId);
            $this->loadRows();
            $this->done();
        }
    }

    /**
     * Save one row.
     *
     * The storefront drops a promotional price that is not below the shelf
     * price — it has nothing to strike through — and used to do so in silence,
     * so a typo looked like the campaign simply not working. It is refused here
     * instead, where somebody is watching.
     */
    public function saveRow(int $productId): void
    {
        if (! $promotion = $this->promotion()) {
            return;
        }

        $product = $promotion->products()->findOrFail($productId);

        $price = (float) ($this->price[$productId] ?? 0);
        $percent = (int) ($this->percent[$productId] ?? 0);

        if ($price > 0 && $percent > 0) {
            $this->addError("price.{$productId}", __('admin.promo_one_or_the_other'));

            return;
        }

        if ($price > 0 && $price >= (float) $product->price) {
            $this->addError("price.{$productId}", __('admin.promo_must_be_lower', ['price' => money($product->price)]));

            return;
        }

        if ($percent > 0 && $percent > 99) {
            $this->addError("percent.{$productId}", __('admin.promo_percent_range'));

            return;
        }

        $promotion->products()->updateExistingPivot($productId, [
            'promo_price' => $price > 0 ? $price : null,
            'discount_percent' => $percent > 0 ? $percent : null,
            'sort_order' => (int) ($this->order[$productId] ?? 0),
        ]);

        $this->resetErrorBag();
        $this->done();
    }

    /* ------------------------------------------------------------------ helpers */

    protected function promotion(): ?Promotion
    {
        return $this->promotionId
            ? Promotion::withTranslation()->find($this->promotionId)
            : null;
    }

    /** The search results, minus whatever is already in the campaign. */
    protected function found(?Promotion $promotion)
    {
        if (! $promotion || mb_strlen(trim($this->search)) < 2) {
            return collect();
        }

        $term = trim($this->search);

        return Product::query()
            ->whereDoesntHave('promotions', fn ($q) => $q->where('promotions.id', $promotion->id))
            ->where(fn ($q) => $q
                ->where('sku', 'like', "%{$term}%")
                ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$term}%")))
            ->withTranslation()
            ->orderByDesc('id')
            ->limit(12)
            ->get();
    }

    protected function loadRows(): void
    {
        $this->reset(['price', 'percent', 'order']);

        if (! $promotion = $this->promotion()) {
            return;
        }

        foreach ($promotion->products as $product) {
            $this->price[$product->id] = $product->pivot->promo_price ? (string) (float) $product->pivot->promo_price : '';
            $this->percent[$product->id] = $product->pivot->discount_percent ? (string) $product->pivot->discount_percent : '';
            $this->order[$product->id] = (int) $product->pivot->sort_order;
        }
    }

    protected function done(): void
    {
        $this->dispatch('toast', message: __('admin.saved'));
    }
}
