<section aria-label="{{ __('procurement_postal.title') }}">
    <h3>{{ __('procurement_postal.title') }}</h3>
    <p class="pr-muted">{{ __('procurement_postal.help') }}</p>

    @if (! ($postalBoard['installed'] ?? false))
        <div class="alert alert-info">{{ __('procurement_postal.errors.not_installed') }}</div>
    @else
        <div class="card"><div class="card-body">
            <form class="pr-postal-picker" wire:submit="addPostalOrder">
                <div>
                    <label class="form-label" for="postal-order-choice">{{ __('procurement_postal.select_order') }}</label>
                    <select id="postal-order-choice" class="form-select" wire:model="postalOrderId">
                        <option value="">{{ __('procurement_postal.choose_order') }}</option>
                        @foreach ($postalCandidates['rows'] ?? [] as $candidate)
                            <option value="{{ $candidate['order_id'] }}">{{ $candidate['number'] }} · {{ $candidate['name'] }} · {{ $candidate['delivery_date'] }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('procurement_postal.add') }}</button>
            </form>
            <p class="small pr-muted mt-1 mb-0">{{ __('procurement_postal.select_help') }}</p>
            @if ($postalCandidates['truncated'] ?? false)<p class="small text-warning mt-1">{{ __('procurement_postal.narrow') }}</p>@endif
        </div></div>

        <div class="pr-counts">
            @foreach (['all', 'waiting', 'picked_up', 'uploaded'] as $postalCount)
                <button type="button" class="pr-count text-start" wire:click="$set('postalStatus', '{{ $postalCount }}')"><strong>{{ $postalBoard['counts'][$postalCount] ?? 0 }}</strong><span>{{ __('procurement_postal.statuses.'.$postalCount) }}</span></button>
            @endforeach
        </div>
        @if ($postalBoard['truncated'] ?? false)<div class="alert alert-info">{{ __('procurement_postal.narrow') }}</div>@endif

        @forelse ($postalBoard['rows'] as $postalRow)
            <article class="pr-postal-card {{ $postalRow['status'] === 'uploaded' ? 'is-uploaded' : '' }} {{ $postalRow['source_changed'] ? 'is-changed' : '' }} {{ ! $postalRow['available'] ? 'is-unavailable' : '' }}" wire:key="postal-order-{{ $postalRow['order_id'] }}">
                <div class="pr-postal-head">
                    <div>
                        @if (($postalRow['source_reason'] ?? '') === 'deleted')
                            <strong class="pr-model">{{ $postalRow['number'] }}</strong>
                        @else
                            <a class="pr-model" href="{{ route('admin.orders.show', $postalRow['order_id']) }}">{{ $postalRow['number'] }}</a>
                        @endif
                        <div class="small pr-muted mt-50">{{ __('procurement_postal.delivery_day') }}: {{ $postalRow['delivery_date'] ?? '—' }}</div>
                    </div>
                    <span class="pr-badge {{ $postalRow['status'] === 'uploaded' ? 'pr-badge-received' : 'pr-badge-unsent' }}">{{ __('procurement_postal.statuses.'.$postalRow['status']) }}</span>
                </div>
                <strong>{{ $postalRow['name'] }}</strong> · <a href="tel:{{ $postalRow['phone'] }}">{{ $postalRow['phone'] }}</a>
                <p class="mb-0 mt-50">{{ $postalRow['address'] ?: '—' }}</p>
                <div class="pr-postal-items">
                    @foreach ($postalRow['items'] as $postalItem)
                        <div class="pr-postal-item"><div><strong>{{ $postalItem['name'] }}</strong><div class="small pr-muted">{{ $postalItem['supplier_name'] ?? '' }}@if (filled($postalItem['supplier_sku'] ?? null)) · {{ __('procurement.native_sku') }}: {{ $postalItem['supplier_sku'] }}@endif</div></div><strong>× {{ $postalItem['qty'] }}</strong></div>
                    @endforeach
                </div>
                @if ($postalRow['source_changed'])<p class="text-warning mb-50">{{ __('procurement_postal.changed') }}</p>@endif
                @if (! $postalRow['available'])<p class="text-danger mb-50">{{ __('procurement_postal.unavailable') }}</p>@endif
                @if ($postalRow['picked_up_at'])<div class="small pr-muted">{{ __('procurement_postal.picked_up_at') }}: {{ \Carbon\CarbonImmutable::parse($postalRow['picked_up_at'])->timezone('Asia/Tbilisi')->format('d.m.Y H:i') }}</div>@endif
                @if ($postalRow['uploaded_at'])<div class="small pr-muted">{{ __('procurement_postal.uploaded_at') }}: {{ \Carbon\CarbonImmutable::parse($postalRow['uploaded_at'])->timezone('Asia/Tbilisi')->format('d.m.Y H:i') }}</div>@endif
                <div class="pr-postal-actions">
                    @if ($postalRow['available'])
                        @if (! $postalRow['picked_up_at'])
                            <button type="button" class="btn btn-primary" wire:click="markPostalPickedUp({{ $postalRow['order_id'] }}, '{{ $postalRow['expected_hash'] }}')" wire:loading.attr="disabled">{{ __('procurement_postal.pickup') }}</button>
                        @elseif (! $postalRow['uploaded_at'] || $postalRow['source_changed'])
                            <button type="button" class="btn btn-success" wire:click="markPostalUploaded({{ $postalRow['order_id'] }}, '{{ $postalRow['expected_hash'] }}')" wire:loading.attr="disabled">{{ __($postalRow['uploaded_at'] ? 'procurement_postal.reupload' : 'procurement_postal.upload') }}</button>
                        @endif
                    @endif
                    @if ($postalRow['uploaded_at'])
                        <button type="button" class="btn btn-outline-secondary" wire:click="undoPostalUploaded({{ $postalRow['order_id'] }}, '{{ $postalRow['expected_hash'] }}')" wire:loading.attr="disabled">{{ __('procurement_postal.undo_uploaded') }}</button>
                    @elseif ($postalRow['picked_up_at'])
                        <button type="button" class="btn btn-outline-secondary" wire:click="undoPostalPickedUp({{ $postalRow['order_id'] }}, '{{ $postalRow['expected_hash'] }}')" wire:loading.attr="disabled">{{ __('procurement_postal.undo_picked_up') }}</button>
                    @else
                        <button type="button" class="btn btn-outline-secondary" wire:click="deselectPostal({{ $postalRow['order_id'] }}, '{{ $postalRow['expected_hash'] }}')" wire:loading.attr="disabled">{{ __('procurement_postal.remove') }}</button>
                    @endif
                </div>
            </article>
        @empty
            <div class="pr-empty"><h4>{{ __('procurement_postal.empty') }}</h4><p class="pr-muted mb-0">{{ __('procurement_postal.empty_help') }}</p></div>
        @endforelse
    @endif
</section>
