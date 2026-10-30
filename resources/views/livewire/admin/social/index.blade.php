<div>
    @if (session('error'))
        <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
    @endif
    @if (session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    <section id="social-suite-preparation" x-data x-on:suite-bundle-ready.window="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'start' }))">
        <x-admin.list-card :title="__('social.manual.title')">
            <div class="card-body pt-0">
                <p class="text-muted">{{ __('social.manual.intro') }}</p>
                <div class="row g-1 align-items-end">
                    <div class="col-lg-8">
                        <label for="social-manual-search" class="form-label">{{ __('social.suite.search') }}</label>
                        <input id="social-manual-search" type="search" wire:model.live.debounce.350ms="manualSearch"
                               class="form-control" maxlength="100" placeholder="{{ __('social.suite.search_placeholder') }}" autocomplete="off">
                    </div>
                    <div class="col-lg-4">
                        <button type="button" class="btn btn-primary w-100" wire:click="publishNow" wire:loading.attr="disabled" @disabled(! $manualSelectedProduct)>
                            <span wire:loading.remove wire:target="publishNow">{{ __('social.manual.publish') }}</span>
                            <span wire:loading wire:target="publishNow">{{ __('social.manual.publishing') }}</span>
                        </button>
                    </div>
                </div>
                @if ($manualMatches->isNotEmpty())
                    <div class="list-group mt-1" role="list" aria-label="{{ __('social.suite.results') }}">
                        @foreach ($manualMatches as $product)
                            <button type="button" class="list-group-item list-group-item-action d-flex flex-wrap justify-content-between align-items-center gap-1 text-start"
                                    wire:click="selectManualProduct({{ $product->id }})" wire:key="suite-product-{{ $product->id }}">
                                <span>
                                    <span class="fw-bold d-block">{{ product_title($product->brand?->name, $product->translatedValue('name', 'ka')) }}</span>
                                    <small class="text-muted">{{ __('social.suite.product_reference', ['id' => $product->id, 'sku' => $product->sku]) }}</small>
                                </span>
                                <span class="text-nowrap">{{ money($product->sellingPrice()) }}</span>
                            </button>
                        @endforeach
                    </div>
                @elseif (mb_strlen(trim($manualSearch)) >= 2 || ctype_digit(trim($manualSearch)))
                    <p class="small text-muted mt-1 mb-0">{{ __('social.suite.no_results') }}</p>
                @endif
                @if ($manualSelectedProduct)
                    <div class="border rounded p-1 mt-1 d-flex flex-wrap align-items-center justify-content-between gap-1">
                        <div>
                            <small class="text-muted d-block">{{ __('social.suite.selected') }}</small>
                            <span class="fw-bold">{{ product_title($manualSelectedProduct->brand?->name, $manualSelectedProduct->translatedValue('name', 'ka')) }}</span>
                            <div class="small text-muted">{{ __('social.suite.product_reference', ['id' => $manualSelectedProduct->id, 'sku' => $manualSelectedProduct->sku]) }}</div>
                        </div>
                        <span class="fw-bold">{{ money($manualSelectedProduct->sellingPrice()) }}</span>
                    </div>
                @endif
                @error('manualProductId')
                    <div class="text-danger small mt-1" role="alert">{{ $message }}</div>
                @enderror
                @error('suiteBundle')
                    <div class="alert alert-danger mt-1 mb-0" role="alert"><div class="alert-body">{{ $message }}</div></div>
                @enderror
                @error('manualPublishing')
                    <div class="alert alert-warning mt-1 mb-0" role="alert"><div class="alert-body">{{ $message }}</div></div>
                @enderror
                <p class="small text-muted mt-1 mb-0">{{ __('social.suite.selection_hint') }}</p>
                <p class="small text-muted mt-50 mb-0">{{ __('social.manual.channel_hint') }}</p>
                @if ($manualSelectedProduct)
                    <button type="button" class="btn btn-outline-secondary mt-1" wire:click="prepareForSuite" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="prepareForSuite">{{ __('social.manual.preview') }}</span>
                        <span wire:loading wire:target="prepareForSuite">{{ __('social.suite.preparing') }}</span>
                    </button>
                @endif
                @if ($manualOutcome && $manualRequestProductId === $manualProductId)
                    <div class="row g-1 mt-1" role="status">
                        @foreach (\App\Services\Social\SocialSettings::CHANNELS as $channel)
                            @php
                                $result = $manualOutcome['publications'][$channel] ?? ['status' => 'disabled', 'permalink' => null, 'has_issue' => false];
                                $resultBadge = match ($result['status']) {
                                    'published' => 'success',
                                    'unknown', 'publishing', 'pending' => 'warning',
                                    'failed', 'blocked' => 'danger',
                                    default => 'secondary',
                                };
                            @endphp
                            <div class="col-sm-6 col-xl-3">
                                <div class="border rounded p-1 h-100">
                                    <h6 class="mb-50">{{ __('social.channels.names.'.$channel) }}</h6>
                                    <span class="badge rounded-pill badge-light-{{ $resultBadge }}">{{ __('social.channel_results.'.$result['status']) }}</span>
                                    @if ($result['permalink'])
                                        <a class="small d-block mt-50" href="{{ $result['permalink'] }}" target="_blank" rel="noopener noreferrer">{{ __('social.manual.view_publication') }}</a>
                                    @endif
                                    @if ($result['has_issue'])
                                        <p class="small text-muted mt-50 mb-0">{{ __('social.manual.channel_issue') }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if ($manualOutcome['unknown'] > 0)
                        <p class="small text-warning mt-1 mb-0">{{ __('social.manual.unknown_hint') }}</p>
                    @endif
                    <button type="button" class="btn btn-sm btn-flat-secondary mt-1" wire:click="newManualRequest" wire:loading.attr="disabled" @disabled($manualOutcome['unknown'] > 0)>
                        {{ __('social.manual.new_request') }}
                    </button>
                @endif
                <details class="border rounded p-1 mt-2" @if ($suiteBundle) open @endif>
                    <summary class="fw-bold">{{ __('social.suite.advanced') }}</summary>
                    <p class="small text-muted mt-1 mb-0">{{ __('social.suite.advanced_hint') }}</p>
                @if ($suiteBundle)
                    <div class="border-top mt-2 pt-2" wire:key="suite-bundle-{{ $suiteBundle['id'] }}" x-data="{ captionCopied: false, linkCopied: false }">
                        <h4 class="mb-50">{{ $suiteBundle['title'] }}</h4>
                        <p class="small text-muted">{{ __('social.suite.expires', ['time' => \Illuminate\Support\Carbon::parse($suiteBundle['expires_at'])->timezone('Asia/Tbilisi')->format('d.m.Y H:i')]) }}</p>
                        <div class="row g-2">
                            @foreach (['feed', 'story'] as $format)
                                <div class="col-md-6">
                                    <h5>{{ __('social.suite.formats.'.$format) }}</h5>
                                    <a href="{{ $suiteBundle['assets'][$format]['preview_url'] }}" target="_blank" rel="noopener">
                                        <img src="{{ $suiteBundle['assets'][$format]['preview_url'] }}"
                                             alt="{{ __('social.suite.preview_alt', ['name' => $suiteBundle['title'], 'format' => __('social.suite.formats.'.$format)]) }}"
                                             class="rounded w-100 border" style="height: 500px; object-fit: contain;"
                                             width="{{ $suiteBundle['assets'][$format]['width'] }}" height="{{ $suiteBundle['assets'][$format]['height'] }}">
                                    </a>
                                    <a class="btn btn-outline-primary w-100 mt-1" href="{{ $suiteBundle['assets'][$format]['download_url'] }}" download>
                                        {{ __('social.suite.download', ['format' => __('social.suite.formats.'.$format)]) }}
                                    </a>
                                </div>
                            @endforeach
                        </div>
                        <div class="row g-1 mt-1">
                            <div class="col-lg-7">
                                <div class="d-flex justify-content-between align-items-center gap-1 mb-50">
                                    <label for="suite-caption" class="form-label mb-0">{{ __('social.suite.caption') }}</label>
                                    <button type="button" class="btn btn-sm btn-flat-primary"
                                            x-on:click="if (navigator.clipboard) { navigator.clipboard.writeText($refs.caption.value).then(() => captionCopied = true).catch(() => { $refs.caption.focus(); $refs.caption.select(); }) } else { $refs.caption.focus(); $refs.caption.select(); }">
                                        <span x-show="! captionCopied">{{ __('social.suite.copy') }}</span>
                                        <span x-show="captionCopied" x-cloak>{{ __('social.suite.copied') }}</span>
                                    </button>
                                </div>
                                <textarea id="suite-caption" x-ref="caption" readonly rows="8" class="form-control" onclick="this.select()">{{ $suiteBundle['caption'] }}</textarea>
                            </div>
                            <div class="col-lg-5">
                                <div class="d-flex justify-content-between align-items-center gap-1 mb-50">
                                    <label for="suite-product-url" class="form-label mb-0">{{ __('social.suite.product_url') }}</label>
                                    <button type="button" class="btn btn-sm btn-flat-primary"
                                            x-on:click="if (navigator.clipboard) { navigator.clipboard.writeText($refs.productUrl.value).then(() => linkCopied = true).catch(() => { $refs.productUrl.focus(); $refs.productUrl.select(); }) } else { $refs.productUrl.focus(); $refs.productUrl.select(); }">
                                        <span x-show="! linkCopied">{{ __('social.suite.copy') }}</span>
                                        <span x-show="linkCopied" x-cloak>{{ __('social.suite.copied') }}</span>
                                    </button>
                                </div>
                                <input id="suite-product-url" x-ref="productUrl" readonly class="form-control" value="{{ $suiteBundle['product_url'] }}" onclick="this.select()">
                                <p class="small text-muted mt-1">{{ __('social.suite.link_hint') }}</p>
                            </div>
                        </div>
                        <div class="alert alert-info mt-2 mb-1"><div class="alert-body">{{ __('social.suite.steps') }}</div></div>
                        <ul class="small text-muted mb-1">
                            <li>{{ __('social.suite.feed_guide') }}</li>
                            <li>{{ __('social.suite.facebook_story_guide') }}</li>
                            <li>{{ __('social.suite.instagram_story_guide') }}</li>
                        </ul>
                        <div class="d-flex flex-wrap gap-1">
                            <a class="btn btn-primary" href="https://business.facebook.com/latest/composer/?asset_id=103447072710661&amp;business_id=904130237562518" target="_blank" rel="noopener noreferrer">{{ __('social.suite.open_feed') }}</a>
                            <a class="btn btn-outline-primary" href="https://business.facebook.com/latest/story_composer/?asset_id=103447072710661&amp;business_id=904130237562518" target="_blank" rel="noopener noreferrer">{{ __('social.suite.open_story') }}</a>
                        </div>
                    </div>
                @endif
                </details>
            </div>
        </x-admin.list-card>
    </section>
    <x-admin.list-card :title="__('social.manual.history_title')" :count="count($manualHistory)">
        <div class="card-body pt-0">
            <p class="small text-muted">{{ __('social.manual.history_hint') }}</p>
            @forelse ($manualHistory as $request)
                <div class="border rounded p-1 mb-1" wire:key="manual-history-{{ $request['id'] }}">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-1 mb-1">
                        <div>
                            <span class="fw-bold">{{ $request['title'] }}</span>
                            <small class="text-muted d-block">{{ __('social.product_id', ['id' => $request['product_id']]) }}</small>
                        </div>
                        <small class="text-muted">{{ $request['created_at'] }} · {{ __('social.timezone') }}</small>
                    </div>
                    <div class="row g-1">
                        @foreach ($request['channels'] as $channel => $publication)
                            @php
                                $historyBadge = match ($publication['status']) {
                                    'published' => 'success',
                                    'unknown', 'publishing', 'pending' => 'warning',
                                    'blocked', 'failed' => 'danger',
                                    default => 'secondary',
                                };
                            @endphp
                            <div class="col-sm-6 col-xl-3">
                                <span class="small fw-bold d-block mb-25">{{ __('social.channels.names.'.$channel) }}</span>
                                <span class="badge rounded-pill badge-light-{{ $historyBadge }}">{{ __('social.channel_results.'.$publication['status']) }}</span>
                                @if ($publication['published_at'])
                                    <small class="text-muted d-block mt-25">{{ $publication['published_at'] }}</small>
                                @endif
                                @if ($publication['permalink'])
                                    <a class="small d-block mt-25" href="{{ $publication['permalink'] }}" target="_blank" rel="noopener noreferrer">{{ __('social.manual.view_publication') }}</a>
                                @endif
                                @if ($publication['has_issue'])
                                    <small class="text-muted d-block mt-25">{{ __('social.manual.channel_issue') }}</small>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="text-muted mb-0">{{ __('social.manual.history_empty') }}</p>
            @endforelse
        </div>
    </x-admin.list-card>
    <div class="row">
        <div class="col-lg-8">
            <x-admin.list-card :title="__('social.page')">
                <div class="card-body pt-0">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-1">
                        <div>
                            <h3 class="mb-50">{{ __('social.page_name') }}</h3>
                            <small class="text-muted">{{ __('social.page_id', ['id' => $values['page_id']]) }}</small>
                        </div>
                        <span @class(['badge rounded-pill', 'badge-light-success' => $connection['connected'], 'badge-light-warning' => ! $connection['connected']])>
                            {{ $connection['connected'] ? __('social.connected') : __('social.not_connected') }}
                        </span>
                    </div>
                    <p class="mb-1">{{ __('social.connection.'.$connection['reason']) }}</p>
                    <div class="border rounded p-1 mb-1">
                        <div class="d-flex justify-content-between align-items-center gap-1">
                            <span class="fw-bold">{{ __('social.channels.instagram_account') }}</span>
                            <span class="badge rounded-pill {{ $connection['instagram']['connected'] ? 'badge-light-success' : 'badge-light-warning' }}">
                                {{ $connection['instagram']['connected'] ? __('social.connected') : __('social.not_connected') }}
                            </span>
                        </div>
                        @if ($connection['instagram']['username'])
                            <div class="small mt-50">{{ '@'.$connection['instagram']['username'] }}</div>
                        @endif
                        <p class="small text-muted mt-50 mb-0">{{ __('social.channel_reasons.'.$connection['instagram']['reason']) }}</p>
                    </div>
                    @if ($connection['last_checked'])
                        <p class="small text-muted mb-1">{{ __('social.last_checked', ['time' => $connection['last_checked']]) }}</p>
                    @endif
                    <div class="d-flex flex-wrap align-items-center gap-1">
                        @if ($connection['oauth_ready'])
                            <a class="btn btn-outline-primary" href="{{ route('admin.social.connect') }}">
                                {{ $connection['connected'] ? __('social.reconnect') : __('social.connect') }}
                            </a>
                        @else
                            <button class="btn btn-outline-secondary" type="button" disabled>
                                {{ __('social.connect') }}
                            </button>
                            <small class="text-muted">{{ __('social.oauth_setup_required') }}</small>
                        @endif
                        <button type="button" class="btn btn-flat-secondary" wire:click="refreshConnection" wire:loading.attr="disabled">
                            {{ __('social.refresh_connection') }}
                        </button>
                    </div>
                    @error('connection')
                        <div class="text-danger small mt-1" role="alert">{{ $message }}</div>
                    @enderror
                    <details class="border-top mt-2 pt-1">
                        <summary class="fw-bold">{{ __('social.token.title') }}</summary>
                        <p class="small text-muted mt-1 mb-1">{{ __('social.token.hint') }}</p>
                        <form method="POST" action="{{ route('admin.social.token') }}" autocomplete="off">
                            @csrf
                            <label for="social-page-token" class="form-label">{{ __('social.token.label') }}</label>
                            <div class="d-flex flex-wrap gap-1">
                                <input id="social-page-token" class="form-control" type="password" name="page_token"
                                       autocomplete="new-password" maxlength="16384" required style="flex: 1; min-width: 200px;">
                                <button class="btn btn-outline-primary" type="submit">{{ __('social.token.connect') }}</button>
                            </div>
                            <p class="small text-muted mt-50 mb-0">{{ __('social.token.security_hint') }}</p>
                        </form>
                    </details>
                </div>
            </x-admin.list-card>
        </div>
        <div class="col-lg-4">
            <x-admin.list-card :title="__('social.today')">
                <div class="card-body pt-0">
                    <div class="d-flex align-items-baseline gap-50 mb-1">
                        <span class="display-6 fw-bold">{{ $publishedCount }}</span>
                        <span class="text-muted">{{ __('social.daily_target', ['count' => $values['posts_per_day']]) }}</span>
                    </div>
                    <p class="mb-50">{{ __('social.ready_count', ['count' => $readyCount]) }}</p>
                    <p class="small text-muted mb-0">{{ __('social.todays_plan', ['count' => $todayCount]) }}</p>
                </div>
            </x-admin.list-card>
        </div>
    </div>

    <form wire:submit="saveConfig">
        <x-admin.list-card :title="__('social.automation')">
            <div class="card-body pt-0">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-2">
                    <div>
                        <h5 class="mb-50">{{ __('social.daily_heading', ['count' => $values['posts_per_day']]) }}</h5>
                        <p class="text-muted mb-0">{{ __('social.automation_hint') }}</p>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="social-enabled" wire:model="enabled">
                        <label class="form-check-label" for="social-enabled">{{ __('social.enabled') }}</label>
                    </div>
                </div>
                <div class="alert {{ $values['enabled'] ? 'alert-success' : 'alert-secondary' }} mb-2" role="status">
                    <div class="alert-body">{{ $values['enabled'] ? ($connection['connected'] ? __('social.automation_on') : __('social.automation_waiting_connection')) : __('social.automation_off') }}</div>
                </div>
                @error('enabled')
                    <div class="text-danger small mb-1">{{ $message }}</div>
                @enderror
                <fieldset class="mb-2">
                    <legend class="form-label">{{ __('social.channels.title') }}</legend>
                    <p class="small text-muted">{{ __('social.channels.hint') }}</p>
                    <div class="row g-1">
                        @foreach (\App\Services\Social\SocialSettings::CHANNELS as $channel)
                            @php
                                $channelState = $connection['channel_status'][$channel];
                            @endphp
                            <div class="col-sm-6 col-xl-3">
                                <div class="border rounded p-1 h-100">
                                    <div class="form-check form-switch mb-50">
                                        <input id="social-channel-{{ $channel }}" class="form-check-input" type="checkbox" wire:model="channels.{{ $channel }}"
                                               @disabled(! ($channels[$channel] ?? false) && ! $channelState['connected'])>
                                        <label class="form-check-label" for="social-channel-{{ $channel }}">{{ __('social.channels.names.'.$channel) }}</label>
                                    </div>
                                    <span class="badge rounded-pill {{ $channelState['connected'] ? 'badge-light-success' : 'badge-light-warning' }}">
                                        {{ $channelState['connected'] ? __('social.channels.ready') : __('social.channels.needs_connection') }}
                                    </span>
                                    <p class="small text-muted mt-50 mb-0">{{ __('social.channel_reasons.'.$channelState['reason']) }}</p>
                                    @error('channels.'.$channel)
                                        <div class="text-danger small mt-50">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @error('channels')
                        <div class="text-danger small mt-50">{{ $message }}</div>
                    @enderror
                </fieldset>
                <div class="row g-1 mb-2">
                    <div class="col-md-5">
                        <label class="form-label" for="social-theme">{{ __('social.theme') }}</label>
                        <select id="social-theme" wire:model.live="theme" class="form-select @error('theme') is-invalid @enderror">
                            @foreach (['auto', 'evergreen', 'black_friday', 'new_year'] as $option)
                                <option value="{{ $option }}">{{ __('social.themes.'.$option) }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">{{ __('social.theme_hint') }}</small>
                        @error('theme')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-7">
                        <p class="form-label mb-50">{{ __('social.eligibility') }}</p>
                        <p class="mb-50">{{ __('social.eligibility_price', ['price' => money($values['minimum_price'])]) }}</p>
                        <p class="small text-muted mb-0">{{ __('social.eligibility_hint', ['days' => $values['cooldown_days']]) }}</p>
                    </div>
                </div>
                <details class="border rounded p-1 mb-2" wire:key="social-artwork-editor" open>
                    <summary class="fw-bold">{{ __('social.design.title') }}</summary>
                    <div class="pt-1">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-1">
                            <p class="small text-muted mb-0">{{ __('social.design.hint') }}</p>
                            <button type="button" class="btn btn-sm btn-flat-secondary" wire:click="resetArtwork" wire:loading.attr="disabled">
                                {{ __('social.design.reset') }}
                            </button>
                        </div>
                        <div class="row g-1">
                            <div class="col-md-4">
                                <label for="social-accent-hex" class="form-label">{{ __('social.design.accent_color') }}</label>
                                <div class="d-flex align-items-center gap-50">
                                    <input type="color" class="form-control p-25" style="width: 48px; height: 38px; flex-shrink: 0;"
                                           aria-label="{{ __('social.design.color_picker') }}"
                                           value="{{ is_string($artwork['accent_color'] ?? null) && preg_match('/\A#[0-9A-Fa-f]{6}\z/D', $artwork['accent_color']) ? $artwork['accent_color'] : $presetAccent }}"
                                           wire:change="$set('artwork.accent_color', $event.target.value)">
                                    <input id="social-accent-hex" type="text" class="form-control @error('artwork.accent_color') is-invalid @enderror"
                                           wire:model.blur="artwork.accent_color" placeholder="{{ $presetAccent }}" maxlength="7" spellcheck="false" autocomplete="off">
                                </div>
                                <small class="text-muted">{{ __('social.design.color_hint') }}</small>
                                @error('artwork.accent_color')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-8">
                                <label for="social-artwork-headline" class="form-label">{{ __('social.design.headline') }}</label>
                                <input id="social-artwork-headline" type="text" class="form-control @error('artwork.headline') is-invalid @enderror"
                                       wire:model="artwork.headline" maxlength="70" placeholder="{{ __('social.artwork.season.'.$presetTheme, [], 'ka') }}">
                                <small class="text-muted">{{ __('social.design.character_limit', ['count' => 70]) }}</small>
                                @error('artwork.headline')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="social-artwork-cta" class="form-label">{{ __('social.design.cta') }}</label>
                                <input id="social-artwork-cta" type="text" class="form-control @error('artwork.cta') is-invalid @enderror"
                                       wire:model="artwork.cta" maxlength="28" placeholder="{{ __('social.artwork.cta', [], 'ka') }}">
                                <small class="text-muted">{{ __('social.design.character_limit', ['count' => 28]) }}</small>
                                @error('artwork.cta')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="social-artwork-tagline" class="form-label">{{ __('social.design.tagline') }}</label>
                                <input id="social-artwork-tagline" type="text" class="form-control @error('artwork.tagline') is-invalid @enderror"
                                       wire:model="artwork.tagline" maxlength="45" placeholder="{{ __('social.artwork.tagline', [], 'ka') }}">
                                <small class="text-muted">{{ __('social.design.character_limit', ['count' => 45]) }}</small>
                                @error('artwork.tagline')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        @error('artwork')
                            <div class="text-danger small mt-50">{{ $message }}</div>
                        @enderror
                        <p class="small text-muted mt-1 mb-0">{{ __('social.design.apply_hint') }}</p>
                    </div>
                </details>
                <fieldset>
                    <legend class="form-label">{{ __('social.schedule') }}</legend>
                    <p class="small text-muted">{{ __('social.schedule_hint') }}</p>
                    <div class="row g-1">
                        @foreach (range(0, 4) as $index)
                            <div class="col-6 col-md">
                                <label class="form-label" for="social-slot-{{ $index }}">{{ __('social.slot', ['number' => $index + 1]) }}</label>
                                <input type="time" id="social-slot-{{ $index }}" wire:model="postingTimes.{{ $index }}"
                                       class="form-control @error('postingTimes.'.$index) is-invalid @enderror" required>
                                @error('postingTimes.'.$index)
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                        @endforeach
                    </div>
                    @error('postingTimes')
                        <div class="text-danger small mt-50">{{ $message }}</div>
                    @enderror
                    <p class="small text-muted mt-1 mb-0">{{ __('social.schedule_saved_hint') }}</p>
                </fieldset>
                <div class="d-flex flex-wrap align-items-center gap-1 mt-2">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('social.save') }}</button>
                    <small class="text-muted">{{ __('social.save_hint') }}</small>
                </div>
            </div>
        </x-admin.list-card>
    </form>

    <x-admin.list-card :title="__('social.preview')">
        <x-slot:actions>
            <button type="button" class="btn btn-outline-secondary" wire:click="regenerateToday" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="regenerateToday">{{ __('social.regenerate_today') }}</span>
                <span wire:loading wire:target="regenerateToday">{{ __('social.regenerating') }}</span>
            </button>
            <button type="button" class="btn btn-outline-primary" wire:click="prepareToday" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="prepareToday">{{ __('social.prepare_today') }}</span>
                <span wire:loading wire:target="prepareToday">{{ __('social.preparing') }}</span>
            </button>
        </x-slot:actions>
        <div class="card-body pt-0">
            <p class="text-muted mb-0">{{ __('social.preview_hint') }}</p>
            <p class="small text-muted mt-50 mb-0">{{ __('social.regenerate_hint') }}</p>
            @if ($regeneration !== null)
                <div class="alert {{ $regeneration['failed'] > 0 ? 'alert-warning' : 'alert-info' }} mt-1 mb-0" role="status">
                    <div class="alert-body">
                        @if ($regeneration['prepared'] === 0 && $regeneration['failed'] === 0)
                            {{ __('social.regenerate_empty') }}
                        @else
                            {{ __('social.regenerated_count', ['count' => $regeneration['prepared']]) }}
                            @if ($regeneration['failed'] > 0)
                                {{ __('social.regenerate_partial', ['count' => $regeneration['failed']]) }}
                            @endif
                        @endif
                    </div>
                </div>
            @endif
            @error('regeneration')
                <div class="alert alert-danger mt-1 mb-0" role="alert"><div class="alert-body">{{ $message }}</div></div>
            @enderror
            @if ($preparation !== null)
                <div class="alert alert-info mt-1 mb-0" role="status">
                    <div class="alert-body">
                        {{ __('social.prepared_count', ['count' => $preparation['prepared']]) }}
                        @if ($preparation['shortfall'] > 0)
                            {{ __('social.shortfall', ['count' => $preparation['shortfall']]) }}
                        @elseif ($preparation['available_slots'] === 0)
                            {{ __('social.no_future_slots') }}
                        @endif
                    </div>
                </div>
            @endif
            @error('preparation')
                <div class="alert alert-danger mt-1 mb-0" role="alert"><div class="alert-body">{{ $message }}</div></div>
            @enderror
        </div>
    </x-admin.list-card>

    <x-admin.list-card :title="__('social.ledger')" :count="$posts->count()">
        @if ($posts->isEmpty())
            <div class="card-body pt-0 text-center py-3">
                <h5>{{ __('social.empty_title') }}</h5>
                <p class="text-muted mb-0">{{ __('social.empty_hint') }}</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('social.artwork_label') }}</th>
                            <th>{{ __('social.product') }}</th>
                            <th>{{ __('social.scheduled') }}</th>
                            <th>{{ __('social.channels.results') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($posts as $post)
                            @php
                                $artworkUrl = route('admin.social.artwork', ['post' => $post->id, 'v' => substr(hash('sha256', (string) $post->image_path), 0, 12)]);
                            @endphp
                            <tr wire:key="social-post-{{ $post->id }}">
                                <td>
                                    @if ($post->image_path)
                                        <a href="{{ $artworkUrl }}" target="_blank" rel="noopener">
                                            <img src="{{ $artworkUrl }}"
                                                 alt="{{ __('social.artwork_alt', ['name' => $post->snapshot['title'] ?? __('social.product')]) }}"
                                                 width="160" height="200" loading="lazy" class="rounded" style="object-fit: contain;">
                                        </a>
                                    @else
                                        <span class="text-muted small">{{ __('social.artwork_pending') }}</span>
                                    @endif
                                </td>
                                <td style="min-width: 240px; max-width: 380px;">
                                    <span class="fw-bold">{{ $post->snapshot['title'] ?? __('social.product_id', ['id' => $post->product_id]) }}</span>
                                    <div class="small text-muted mt-25">{{ __('social.themes.'.$post->theme) }}</div>
                                    @if (is_numeric($post->snapshot['price'] ?? null))
                                        <div class="small mt-50">{{ money((float) $post->snapshot['price']) }}</div>
                                    @endif
                                    @if ($post->caption)
                                        <details class="small mt-50">
                                            <summary>{{ __('social.caption') }}</summary>
                                            <p class="mt-50 mb-0" style="white-space: pre-line;">{{ $post->caption }}</p>
                                        </details>
                                    @endif
                                    @if (in_array($post->status, ['draft', 'ready'], true) && $post->scheduled_for->isFuture() && $post->scheduled_for->setTimezone('Asia/Tbilisi')->isToday())
                                        <button type="button" class="btn btn-sm btn-outline-primary mt-1" wire:click="preparePostForSuite({{ $post->id }})" wire:loading.attr="disabled">
                                            {{ __('social.suite.prepare') }}
                                        </button>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    {{ $post->scheduled_for->timezone('Asia/Tbilisi')->format('d.m.Y H:i') }}
                                    <div class="small text-muted">{{ __('social.timezone') }}</div>
                                </td>
                                <td style="min-width: 270px;">
                                    @foreach ($ledgerChannels[$post->id] as $channel => $publication)
                                        @php
                                            $channelBadge = match ($publication['status']) {
                                            'published' => 'success',
                                            'ready' => 'info',
                                            'unknown', 'publishing', 'pending' => 'warning',
                                            'failed', 'blocked' => 'danger',
                                            default => 'secondary',
                                            };
                                        @endphp
                                        <div class="mb-1">
                                            <span class="small d-block fw-bold mb-25">{{ __('social.channels.names.'.$channel) }}</span>
                                            <span class="badge rounded-pill badge-light-{{ $channelBadge }}">{{ __('social.channel_results.'.$publication['status']) }}</span>
                                            @if ($publication['published_at'])
                                                <span class="small text-muted ms-50">{{ $publication['published_at'] }}</span>
                                            @endif
                                            @if ($publication['permalink'])
                                                <a class="small d-block mt-25" href="{{ $publication['permalink'] }}" target="_blank" rel="noopener noreferrer">{{ __('social.manual.view_publication') }}</a>
                                            @endif
                                            @if ($publication['has_issue'])
                                                <span class="small text-muted d-block mt-25">{{ __('social.manual.channel_issue') }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.list-card>
</div>
