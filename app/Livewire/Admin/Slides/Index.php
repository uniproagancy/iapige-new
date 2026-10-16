<?php

namespace App\Livewire\Admin\Slides;

use App\Models\Language;
use App\Models\Slide;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class Index extends Component
{
    use WithFileUploads;

    public bool $showForm = false;

    public ?int $editingId = null;

    public $image = null;

    public ?string $currentImage = null;

    public string $url = '';

    public bool $is_active = true;

    public ?string $starts_at = null;

    public ?string $ends_at = null;

    public int $sort_order = 0;

    public array $translations = [];

    public function render()
    {
        return view('livewire.admin.slides.index', [
            'slides' => Slide::withTranslation()->orderBy('sort_order')->orderBy('id')->get(),
            'languages' => Language::active(),

            'liveCount' => Slide::live()->count(),
        ])->layout('layouts.admin', ['title' => __('admin.slides')]);
    }

    public function create(): void
    {
        $this->reset(['editingId', 'image', 'currentImage', 'url', 'starts_at', 'ends_at']);

        $this->is_active = true;

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

                $this->forgetImage($slide);

                $slide->image = $this->image->store('slides', 'public');
            }

            $slide->save();

            $rows = [];

            foreach ($this->translations as $locale => $fields) {

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

    public function toggleActive(int $id): void
    {
        $slide = Slide::findOrFail($id);

        $slide->update(['is_active' => ! $slide->is_active]);
    }

    public function move(int $id, int $by): void
    {
        $slides = Slide::orderBy('sort_order')->orderBy('id')->get();
        $at = $slides->search(fn (Slide $s) => $s->id === $id);

        if ($at === false) {
            return;
        }

        $to = $at + $by;

        if ($to < 0 || $to >= $slides->count()) {
            return;
        }

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
        $slide->delete();

        if ($this->editingId === $id) {
            $this->showForm = false;
        }

        $this->dispatch('toast', message: __('admin.deleted'));
    }

    protected function forgetImage(Slide $slide): void
    {
        if ($slide->image && ! str_starts_with($slide->image, 'http')) {
            Storage::disk('public')->delete($slide->image);
        }
    }

    protected function blankTranslations(): array
    {
        return Language::active()->mapWithKeys(fn (Language $l) => [
            $l->code => ['dates' => '', 'title' => '', 'sub' => '', 'cta' => ''],
        ])->all();
    }
}
