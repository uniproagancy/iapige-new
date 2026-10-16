@php
    $icon = function (string $name, string $class = '') {
        $paths = [
            'plus'  => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'edit'  => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
            'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
            'up'    => '<polyline points="18 15 12 9 6 15"/>',
            'down'  => '<polyline points="6 9 12 15 18 9"/>',
            'eye'   => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'eyeOff'=> '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
            'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
            'link'  => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        ];

        return '<svg class="ic '.$class.'" width="14" height="14" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
    };

    $default = \App\Models\Language::defaultCode();

    /*
     | Sized here rather than in the stylesheet.
     |
     | admin.css is built by Vite, and until that build is deployed a
     | stylesheet rule does not exist — which is how the first version of this
     | screen put a 1440px photograph across the whole page. The carousel's
     | own proportions are the point of the preview, so they travel with the
     | markup.
     */
    $thumb = 'width:132px;aspect-ratio:1440/530;object-fit:cover;border-radius:.357rem;background:rgba(255,255,255,.06);flex-shrink:0';
    $preview = 'width:100%;max-width:460px;aspect-ratio:1440/530;object-fit:cover;border-radius:.428rem;background:rgba(255,255,255,.06);display:block';
@endphp

<div>

    <div class="card">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-1">
            <div>
                <h4 class="mb-25 d-flex align-items-center gap-50">
                    {!! $icon('image') !!}{{ __('admin.slides') }}
                </h4>
                <small class="text-muted">{{ __('admin.slides_showing', ['count' => $liveCount]) }}</small>
            </div>

            <button type="button" class="btn btn-primary" wire:click="create">
                {!! $icon('plus', 'me-50') !!}{{ __('admin.create') }}
            </button>
        </div>
    </div>

    @if ($slides->isEmpty() && ! $showForm)
        <div class="card">
            <div class="card-body text-center py-3">
                <span class="d-inline-flex align-items-center justify-content-center mb-1"
                      style="width:56px;height:56px;border-radius:50%;background:rgba(255,255,255,.06)">
                    {!! $icon('image') !!}
                </span>
                <p class="text-muted mb-0" style="max-width:520px;margin:0 auto">{{ __('admin.slides_none') }}</p>
            </div>
        </div>
    @endif

    @if ($showForm)
        <div class="card border-primary">
            <div class="card-header pb-0">
                <h5 class="mb-0">{{ $editingId ? __('admin.edit') : __('admin.create') }}</h5>
            </div>

            <div class="card-body">
                <div class="row g-2">

                    <div class="col-lg-5">
                        <label class="form-label fw-bolder" for="slideImage">{{ __('admin.slide_image') }}</label>

                        @if ($image)
                            <img src="{{ $image->temporaryUrl() }}" style="{{ $preview }}" class="mb-1" alt="">
                        @elseif ($currentImage)
                            <img src="{{ $currentImage }}" style="{{ $preview }}" class="mb-1" alt="">
                        @else
                            <div class="d-flex align-items-center justify-content-center mb-1 text-muted"
                                 style="{{ $preview }}">
                                {!! $icon('image') !!}
                            </div>
                        @endif

                        <input type="file" id="slideImage" class="form-control @error('image') is-invalid @enderror"
                               accept=".jpg,.jpeg,.png,.webp" wire:model="image">
                        @error('image') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror

                        <small class="text-muted d-block mt-25">{{ __('admin.slide_image_hint') }}</small>
                        <div wire:loading wire:target="image" class="small text-primary">{{ __('admin.upload') }}…</div>
                    </div>

                    <div class="col-lg-7">
                        <div class="row g-1">
                            <div class="col-md-8">
                                <label class="form-label" for="slideUrl">{{ __('admin.slide_url') }}</label>
                                <div class="input-group input-group-merge">
                                    <span class="input-group-text">{!! $icon('link') !!}</span>
                                    <input type="text" id="slideUrl" class="form-control"
                                           placeholder="{{ __('admin.slide_url_hint') }}" wire:model="url">
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="slideOrder">{{ __('admin.sort_order') }}</label>
                                <input type="number" id="slideOrder" min="0" class="form-control" wire:model="sort_order">
                            </div>

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

                <hr class="my-2">

                @foreach ($languages as $language)
                    <div class="mb-2" wire:key="lang-{{ $language->code }}">
                        <div class="d-flex align-items-center gap-50 mb-50">
                            <span class="badge badge-light-secondary text-uppercase">{{ $language->code }}</span>
                            <span class="fw-bolder">{{ $language->native_name }}</span>
                            @if ($language->code === $default)
                                <span class="badge badge-light-primary">{{ __('admin.required') }}</span>
                            @endif
                        </div>

                        <div class="row g-1">
                            <div class="col-md-3">
                                <label class="form-label">{{ __('admin.slide_dates') }}</label>
                                <input type="text" class="form-control"
                                       placeholder="26—31 {{ __('admin.slide_dates_example') }}"
                                       wire:model="translations.{{ $language->code }}.dates">
                            </div>

                            <div class="col-md-5">
                                <label class="form-label">{{ __('admin.slide_title') }}</label>

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

    @if ($slides->isNotEmpty())
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th style="width:150px">{{ __('admin.slide_image') }}</th>
                            <th>{{ __('admin.slide_title') }}</th>
                            <th style="width:140px">{{ __('admin.status') }}</th>
                            <th class="text-end" style="width:230px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($slides as $slide)
                            <tr wire:key="slide-{{ $slide->id }}">
                                <td>
                                    @if ($slide->imageUrl())
                                        <img src="{{ $slide->imageUrl() }}" style="{{ $thumb }}" alt="" loading="lazy">
                                    @else
                                        <div class="d-flex align-items-center justify-content-center text-muted"
                                             style="{{ $thumb }}">{!! $icon('image') !!}</div>
                                    @endif
                                </td>

                                <td>
                                    @if ($slide->dates)
                                        <small class="text-muted d-block">{{ $slide->dates }}</small>
                                    @endif

                                    <b class="d-block" style="white-space:pre-line">{{ $slide->title ?: '—' }}</b>

                                    @if ($slide->sub)
                                        <small class="text-muted">{{ $slide->sub }}</small>
                                    @endif

                                    <div class="mt-25 d-flex flex-wrap align-items-center gap-50">
                                        @if ($slide->cta)
                                            <span class="badge badge-light-secondary">{{ $slide->cta }}</span>
                                        @endif

                                        @if ($slide->url)
                                            <small class="text-muted text-truncate" style="max-width:280px">
                                                {!! $icon('link') !!} {{ $slide->url }}
                                            </small>
                                        @endif
                                    </div>
                                </td>

                                <td>

                                    @if (! $slide->is_active)
                                        <span class="badge badge-light-secondary">{{ __('admin.inactive') }}</span>
                                    @elseif ($slide->starts_at && $slide->starts_at->isFuture())
                                        <span class="badge badge-light-info">
                                            {!! $icon('clock') !!} {{ $slide->starts_at->format('d.m.Y') }}
                                        </span>
                                    @elseif ($slide->ends_at && $slide->ends_at->isPast())
                                        <span class="badge badge-light-warning">{{ __('admin.slide_finished') }}</span>
                                    @else
                                        <span class="badge badge-light-success">
                                            {!! $icon('eye') !!} {{ __('admin.slide_showing') }}
                                        </span>
                                    @endif

                                    @if ($slide->ends_at && ! $slide->ends_at->isPast())
                                        <small class="text-muted d-block mt-25">
                                            → {{ $slide->ends_at->format('d.m.Y') }}
                                        </small>
                                    @endif
                                </td>

                                <td class="text-end text-nowrap">
                                    <div class="btn-group btn-group-sm me-50" role="group">
                                        <button type="button" class="btn btn-outline-secondary"
                                                wire:click="move({{ $slide->id }}, -1)" @disabled($loop->first)
                                                title="{{ __('admin.move_up') }}">{!! $icon('up') !!}</button>

                                        <button type="button" class="btn btn-outline-secondary"
                                                wire:click="move({{ $slide->id }}, 1)" @disabled($loop->last)
                                                title="{{ __('admin.move_down') }}">{!! $icon('down') !!}</button>
                                    </div>

                                    <button type="button" class="btn btn-sm btn-icon btn-outline-info"
                                            wire:click="toggleActive({{ $slide->id }})"
                                            title="{{ $slide->is_active ? __('admin.hide') : __('admin.show') }}">
                                        {!! $icon($slide->is_active ? 'eyeOff' : 'eye') !!}
                                    </button>

                                    <button type="button" class="btn btn-sm btn-icon btn-outline-primary"
                                            wire:click="edit({{ $slide->id }})"
                                            title="{{ __('admin.edit') }}">{!! $icon('edit') !!}</button>

                                    <button type="button" class="btn btn-sm btn-icon btn-outline-danger"
                                            wire:click="delete({{ $slide->id }})"
                                            wire:confirm="{{ __('admin.confirm_delete') }}"
                                            title="{{ __('admin.delete') }}">{!! $icon('trash') !!}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
