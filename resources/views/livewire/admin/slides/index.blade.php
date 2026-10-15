@php
    $icon = function (string $name, string $class = '') {
        $paths = [
            'plus'  => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'edit'  => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
            'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
            'up'    => '<polyline points="18 15 12 9 6 15"/>',
            'down'  => '<polyline points="6 9 12 15 18 9"/>',
            'eye'   => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        ];

        return '<svg class="ic '.$class.'" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };

    $default = \App\Models\Language::defaultCode();
@endphp

<div>
    <div class="card">
        <div class="card-body pb-1 d-flex flex-wrap align-items-center justify-content-between gap-1">
            <div>
                <h5 class="mb-25">{{ __('admin.slides') }}</h5>
                <small class="text-muted">{{ __('admin.slides_showing', ['count' => $liveCount]) }}</small>
            </div>

            <button type="button" class="btn btn-sm btn-primary" wire:click="create">
                {!! $icon('plus', 'me-25') !!}{{ __('admin.create') }}
            </button>
        </div>
    </div>

    {{--
        With nothing here the shop draws the five it was built with, so the
        screen says so rather than looking as though the carousel is empty.
    --}}
    @if ($slides->isEmpty())
        <div class="card">
            <div class="card-body">
                <p class="mb-0 text-muted">{{ __('admin.slides_none') }}</p>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------ the form --}}
    @if ($showForm)
        <div class="card border-primary">
            <div class="card-body">
                <div class="row g-1">
                    <div class="col-md-5">
                        <label class="form-label" for="slideImage">{{ __('admin.slide_image') }}</label>
                        <input type="file" id="slideImage" class="form-control @error('image') is-invalid @enderror"
                               accept=".jpg,.jpeg,.png,.webp" wire:model="image">
                        @error('image') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror

                        <small class="text-muted d-block mt-25">{{ __('admin.slide_image_hint') }}</small>

                        <div wire:loading wire:target="image" class="small text-muted">{{ __('admin.upload') }}…</div>

                        @if ($image)
                            <img src="{{ $image->temporaryUrl() }}" class="slide-preview mt-1" alt="">
                        @elseif ($currentImage)
                            <img src="{{ $currentImage }}" class="slide-preview mt-1" alt="">
                        @endif
                    </div>

                    <div class="col-md-7">
                        <div class="row g-1">
                            <div class="col-md-8">
                                <label class="form-label" for="slideUrl">{{ __('admin.slide_url') }}</label>
                                <input type="text" id="slideUrl" class="form-control"
                                       placeholder="{{ __('admin.slide_url_hint') }}" wire:model="url">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="slideOrder">{{ __('admin.sort_order') }}</label>
                                <input type="number" id="slideOrder" min="0" class="form-control" wire:model="sort_order">
                            </div>

                            {{-- a dated slide takes itself down rather than waiting to be noticed --}}
                            <div class="col-md-6">
                                <label class="form-label" for="slideFrom">{{ __('admin.slide_from') }}</label>
                                <input type="datetime-local" id="slideFrom" class="form-control" wire:model="starts_at">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="slideTo">{{ __('admin.slide_to') }}</label>
                                <input type="datetime-local" id="slideTo"
                                       class="form-control @error('ends_at') is-invalid @enderror" wire:model="ends_at">
                                @error('ends_at') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-12">
                                <div class="form-check form-switch mt-1">
                                    <input type="checkbox" class="form-check-input" id="slideActive" wire:model="is_active">
                                    <label class="form-check-label" for="slideActive">{{ __('admin.active') }}</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ------------------------------------------------- the words --}}
                <hr>

                @foreach ($languages as $language)
                    <div class="mb-1" wire:key="lang-{{ $language->code }}">
                        <h6 class="text-uppercase text-muted">
                            {{ $language->native_name }}
                            @if ($language->code === $default)
                                <span class="badge badge-light-primary">{{ __('admin.required') }}</span>
                            @endif
                        </h6>

                        <div class="row g-1">
                            <div class="col-md-3">
                                <label class="form-label">{{ __('admin.slide_dates') }}</label>
                                <input type="text" class="form-control"
                                       placeholder="26—31 {{ __('admin.slide_dates_example') }}"
                                       wire:model="translations.{{ $language->code }}.dates">
                            </div>

                            <div class="col-md-5">
                                <label class="form-label">{{ __('admin.slide_title') }}</label>
                                {{-- two lines: the carousel breaks the title where you do --}}
                                <textarea rows="2"
                                          class="form-control @error("translations.{$language->code}.title") is-invalid @enderror"
                                          placeholder="{{ __('admin.slide_title_hint') }}"
                                          wire:model="translations.{{ $language->code }}.title"></textarea>
                                @error("translations.{$language->code}.title")
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">{{ __('admin.slide_sub') }}</label>
                                <input type="text" class="form-control"
                                       wire:model="translations.{{ $language->code }}.sub">

                                <label class="form-label mt-50">{{ __('admin.slide_cta') }}</label>
                                <input type="text" class="form-control"
                                       wire:model="translations.{{ $language->code }}.cta">
                            </div>
                        </div>
                    </div>
                @endforeach

                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                        {{ __('admin.save') }}
                    </button>
                    <button type="button" class="btn btn-outline-secondary" wire:click="cancel">
                        {{ __('admin.cancel') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------ the list --}}
    @foreach ($slides as $i => $slide)
        <div class="card" wire:key="slide-{{ $slide->id }}">
            <div class="card-body d-flex flex-wrap align-items-center gap-1">
                <span class="slide-thumb flex-shrink-0">
                    @if ($slide->imageUrl())
                        <img src="{{ $slide->imageUrl() }}" alt="" loading="lazy">
                    @endif
                </span>

                <div class="flex-grow-1" style="min-width:220px">
                    <small class="text-muted d-block">{{ $slide->dates }}</small>
                    <b class="d-block">{{ $slide->title ?: '—' }}</b>
                    <small class="text-muted">{{ $slide->sub }}</small>

                    <div class="mt-25 d-flex flex-wrap align-items-center gap-25">
                        @if ($slide->cta)
                            <span class="badge badge-light-secondary">{{ $slide->cta }}</span>
                        @endif

                        @if ($slide->url)
                            <small class="text-muted">{{ $slide->url }}</small>
                        @endif
                    </div>
                </div>

                <div class="text-end">
                    {{--
                        Switched on is not the same as showing: a slide whose
                        dates have passed is still active and still invisible,
                        and that is exactly the state somebody rings about.
                    --}}
                    @if (! $slide->is_active)
                        <span class="badge badge-light-secondary">{{ __('admin.inactive') }}</span>
                    @elseif ($slide->starts_at && $slide->starts_at->isFuture())
                        <span class="badge badge-light-info">
                            {!! $icon('clock') !!} {{ $slide->starts_at->format('d.m.Y') }}
                        </span>
                    @elseif ($slide->ends_at && $slide->ends_at->isPast())
                        <span class="badge badge-light-warning">{{ __('admin.slide_finished') }}</span>
                    @else
                        <span class="badge badge-light-success">{!! $icon('eye') !!} {{ __('admin.slide_showing') }}</span>
                    @endif

                    <div class="mt-50 d-flex justify-content-end gap-25">
                        <button type="button" class="btn btn-sm btn-icon btn-outline-secondary"
                                wire:click="move({{ $slide->id }}, -1)" @disabled($loop->first)
                                title="{{ __('admin.move_up') }}">{!! $icon('up') !!}</button>

                        <button type="button" class="btn btn-sm btn-icon btn-outline-secondary"
                                wire:click="move({{ $slide->id }}, 1)" @disabled($loop->last)
                                title="{{ __('admin.move_down') }}">{!! $icon('down') !!}</button>

                        <button type="button" class="btn btn-sm btn-outline-info"
                                wire:click="toggleActive({{ $slide->id }})">
                            {{ $slide->is_active ? __('admin.hide') : __('admin.show') }}
                        </button>

                        <button type="button" class="btn btn-sm btn-icon btn-outline-primary"
                                wire:click="edit({{ $slide->id }})">{!! $icon('edit') !!}</button>

                        <button type="button" class="btn btn-sm btn-icon btn-outline-danger"
                                wire:click="delete({{ $slide->id }})"
                                wire:confirm="{{ __('admin.confirm_delete') }}">{!! $icon('trash') !!}</button>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>
