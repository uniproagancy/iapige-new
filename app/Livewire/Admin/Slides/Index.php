<?php

namespace App\Livewire\Admin\Slides;

use App\Models\Language;
use App\Models\Slide;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The home page's carousel, edited rather than deployed.
 *
 * The five slides lived in Support\Store with their pictures fetched from
 * picsum.photos by number, so changing the dates on the banner meant editing
 * PHP — which is why it still said "26—31 August" in the middle of October.
 *
 * A short list with an inline form, like brands and categories: there are
 * five of these, not five thousand, and the useful things to do to one are
 * switch it off, move it and change its picture.
 */
class Index extends Component
{
    use WithFileUploads;

    public bool $showForm = false;

    public ?int $editingId = null;

    /* ---- form ---- */
    public $image = null;

    public ?string $currentImage = null;

    public string $url = '';

    public bool $is_active = true;

    public ?string $starts_at = null;

    public ?string $ends_at = null;

    public int $sort_order = 0;

    /** @var array<string, array<string, string>> */
    public array $translations = [];

    public function render()
    {
        return view('livewire.admin.slides.index', [
            'slides' => Slide::withTranslation()->orderBy('sort_order')->orderBy('id')->get(),
            'languages' => Language::active(),
            // what the shop is drawing right now, which is not always what is listed
            'liveCount' => Slide::live()->count(),
        ])->layout('layouts.admin', ['title' => __('admin.slides')]);
    }

    /* ------------------------------------------------------------------ the form */

    public function create(): void
    {
        $this->reset(['editingId', 'image', 'currentImage', 'url', 'starts_at', 'ends_at']);

        $this->is_active = true;
        // a new slide goes to the end, where it cannot displace what is running
        $this->sort_order = (int) Slide::max('sort_order') + 1;
        $this->translations = $this->blankTranslations();

        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $slide = Slide::findOrFail($id);

        $this->editingId = $slide->id;
        $this->url = (string) $slide->url;
        $this->is_active = $slide->is_active;
        $this->starts_at = $slide->starts_at?->format('Y-m-d\TH:i');
        $this->ends_at = $slide->ends_at?->format('Y-m-d\TH:i');
        $this->sort_order = $slide->sort_order;
        $this->currentImage = $slide->imageUrl();
        $this->image = null;

        $this->translations = Language::active()->mapWithKeys(fn (Language $l) => [
            $l->code => [
                'dates' => $slide->translate($l->code, false)?->dates ?? '',
                'title' => $slide->translate($l->code, false)?->title ?? '',
                'sub' => $slide->translate($l->code, false)?->sub ?? '',
                'cta' => $slide->translate($l->code, false)?->cta ?? '',
            ],
        ])->all();

        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $default = Language::defaultCode();

        $this->validate([
            /*
             * mimes rather than the image rule: that one lets an SVG through,
             * and an SVG is a document that can carry script. This picture is
             * served from our own domain across the whole front page.
             */
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'url' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            "translations.{$default}.title" => ['required', 'string', 'max:255'],
        ], [
            'ends_at.after' => __('admin.slide_dates_backwards'),
        ]);

        DB::transaction(function () {
            $slide = $this->editingId ? Slide::findOrFail($this->editingId) : new Slide;

            $slide->fill([
                'url' => $this->url ?: null,
                'is_active' => $this->is_active,
                'starts_at' => $this->starts_at ?: null,
                'ends_at' => $this->ends_at ?: null,
                'sort_order' => $this->sort_order,
            ]);

            if ($this->image) {
                // the one it replaces is of no use to anybody afterwards
                $this->forgetImage($slide);

                $slide->image = $this->image->store('slides', 'public');
            }

            $slide->save();

            $rows = [];

            foreach ($this->translations as $locale => $fields) {
                /*
                 * A language nobody filled in is left out rather than written
                 * blank: HasTranslations falls back to the default one, so an
                 * empty row would replace a readable slide with nothing.
                 */
                if (blank($fields['title'] ?? null)) {
                    continue;
                }

                $rows[$locale] = [
                    'dates' => $fields['dates'] ?: null,
                    'title' => $fields['title'],
                    'sub' => $fields['sub'] ?: null,
                    'cta' => $fields['cta'] ?: null,
                ];
            }

            $slide->saveTranslations($rows);
        });

        $this->showForm = false;
        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->reset(['image', 'currentImage']);
        $this->resetValidation();
    }

    /* ------------------------------------------------------------------ the list */

    public function toggleActive(int $id): void
    {
        $slide = Slide::findOrFail($id);

        $slide->update(['is_active' => ! $slide->is_active]);
    }

    /**
     * Moves a slide up or down the carousel.
     *
     * Swapped with its neighbour rather than renumbered, so two slides
     * sharing a sort_order — which nothing prevents — still move one past the
     * other instead of sitting still.
     */
    public function move(int $id, int $by): void
    {
        $slides = Slide::orderBy('sort_order')->orderBy('id')->get();
        $at = $slides->search(fn (Slide $s) => $s->id === $id);

        if ($at === false) {
            return;
        }

        $to = $at + $by;

        if ($to < 0 || $to >= $slides->count()) {
            return;   // already at the end it is being pushed towards
        }

        // renumbered from the order they are actually in, then the two swap
        $order = $slides->pluck('id')->all();
        [$order[$at], $order[$to]] = [$order[$to], $order[$at]];

        DB::transaction(function () use ($order) {
            foreach ($order as $position => $slideId) {
                Slide::whereKey($slideId)->update(['sort_order' => $position]);
            }
        });
    }

    public function delete(int $id): void
    {
        $slide = Slide::findOrFail($id);

        $this->forgetImage($slide);
        $slide->delete();   // its translations go with it

        if ($this->editingId === $id) {
            $this->showForm = false;
        }

        $this->dispatch('toast', message: __('admin.deleted'));
    }

    /* ------------------------------------------------------------------ helpers */

    /**
     * Takes the picture off the disk with the slide that referred to it.
     *
     * Nothing else points at it — the path is this slide's own column — so a
     * deleted slide otherwise leaves its photograph behind for ever, and the
     * carousel is where the largest pictures on the site are uploaded.
     */
    protected function forgetImage(Slide $slide): void
    {
        if ($slide->image && ! str_starts_with($slide->image, 'http')) {
            Storage::disk('public')->delete($slide->image);
        }
    }

    /** @return array<string, array<string, string>> */
    protected function blankTranslations(): array
    {
        return Language::active()->mapWithKeys(fn (Language $l) => [
            $l->code => ['dates' => '', 'title' => '', 'sub' => '', 'cta' => ''],
        ])->all();
    }
}
