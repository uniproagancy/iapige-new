<div class="warranty-delivery-panel border-top p-1" wire:poll.30s.visible>
    <strong class="d-block mb-1">{{ __('warranty.courier.panel_title') }}</strong>
    @if ($serialNotice)<div class="alert alert-success py-50 mb-1" role="status">{{ $serialNotice }}</div>@endif
    @if ($sendStatus)
        <div class="alert alert-info py-50 mb-1" role="status">{{ __('warranty.sms_result.'.$sendStatus) }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger py-50 mb-1" role="alert">
            @foreach ($errors->all() as $message)<div>{{ $message }}</div>@endforeach
        </div>
    @endif
    @foreach ($releasedDrafts as $draft)
        @php
            $releaseState = $releaseStates[$draft->id];
        @endphp
        <div class="border rounded p-1 mb-1" wire:key="delivery-warranty-serial-{{ $draft->id }}">
            <strong class="d-block">{{ $draft->deliveryItem?->name ?: '—' }}</strong>
            <small class="text-muted d-block">{{ __('warranty.unit_index') }} {{ $draft->unit_index }} · {{ __('warranty.courier.serial_pending') }}</small>
            @if ($releaseState['ready'])
                <form wire:submit="completeSerial({{ $draft->id }})" class="mt-1">
                    <label class="form-label" for="delivery-warranty-serial-input-{{ $draft->id }}">{{ __('warranty.courier.serial_input') }}</label>
                    <div class="d-flex flex-wrap gap-1"><input id="delivery-warranty-serial-input-{{ $draft->id }}" class="form-control" maxlength="191" required autocomplete="off" wire:model="serialNumbers.{{ $draft->id }}"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="completeSerial">{{ __('warranty.courier.serial_save') }}</button></div>
                </form>
            @else
                <div class="alert alert-warning mt-1 mb-0">{{ __('warranty.courier.serial_release_stale') }}</div>
            @endif
        </div>
    @endforeach
    @forelse ($certificates as $certificate)
        @php
            $snapshot = $certificate->snapshot;
            $attempt = $attempts->get($certificate->id);
            $resendState = $resendStates[$certificate->id] ?? ['canResend' => false, 'retryAfterSeconds' => 0, 'reason' => null];
        @endphp
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-1" wire:key="delivery-warranty-{{ $certificate->id }}">
            <div>
                <strong class="d-block">{{ data_get($snapshot, 'item.name') ?: '—' }}</strong>
                <small class="text-muted d-block">{{ $certificate->number }} · {{ __('warranty.statuses.issued') }}</small>
                <small class="text-muted d-block">{{ __('warranty.supplier_sku') }}: {{ data_get($snapshot, 'item.supplier_sku') ?: '—' }}</small>
                <small class="text-muted d-block">{{ __('warranty.courier.serial_input') }}: {{ data_get($snapshot, 'item.serial_number') ?: '—' }}</small>
                @if ($attempt)
                    <small class="text-muted d-block">{{ __('warranty.sms_result.'.$attempt->status) }}</small>
                    <small class="text-muted d-block">{{ __('warranty.sms.resend_hint') }}</small>
                    @if ($resendState['reason'] === 'sms_in_progress')
                        <small class="text-muted d-block">{{ __('warranty.errors.sms_in_progress') }}</small>
                    @elseif ((int) $resendState['retryAfterSeconds'] > 0)
                        <small class="text-muted d-block">{{ __('warranty.sms.resend_wait', ['seconds' => (int) $resendState['retryAfterSeconds']]) }}</small>
                    @endif
                @endif
            </div>
            <div class="d-flex flex-wrap gap-1">
                <a class="btn btn-sm btn-outline-primary" href="{{ route('courier.warranties.print', ['delivery' => $deliveryId, 'certificate' => $certificate->id]) }}" target="_blank" rel="noopener">{{ __('warranty.courier.panel_print') }}</a>
                @if ($attempt)
                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="resend({{ $certificate->id }}, {{ $attempt->id }})" wire:loading.attr="disabled" wire:target="resend" @disabled(! $sendingEnabled || ! $resendState['canResend'])>{{ __('warranty.sms.resend') }}</button>
                @else
                    <button type="button" class="btn btn-sm btn-primary" wire:click="send({{ $certificate->id }})" wire:loading.attr="disabled" wire:target="send" @disabled(! $sendingEnabled)>{{ __('warranty.courier.panel_send') }}</button>
                @endif
            </div>
        </div>
    @empty
        @if ($releasedDrafts->isEmpty())<small class="text-muted">{{ __('warranty.courier.panel_empty') }}</small>@endif
    @endforelse
    @if ($certificates->isNotEmpty() && ! $sendingEnabled)
        <small class="text-muted d-block">{{ __('warranty.courier.sending_disabled') }}</small>
    @endif
</div>
