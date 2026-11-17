<div>
    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="card">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-1">
            <div><h4 class="mb-50">ტალონების არქივი</h4><p class="mb-0 text-muted">ტალონის მომზადება და SMS-ით გაგზავნა ხდება უშუალოდ შეკვეთაში ან მიწოდებებში.</p></div>
            <div class="d-flex flex-wrap gap-1">
                <a class="btn btn-primary" href="{{ route('admin.warranties.services') }}" wire:navigate>მომწოდებლების საგარანტიოები</a>
                <a class="btn btn-outline-primary" href="{{ route('admin.deliveries') }}" wire:navigate>მიწოდებებში გადასვლა</a>
            </div>
        </div>
        @if ($isOwner)
            <div class="card-body border-top pt-1">
                <details><summary>მომწოდებლების საგარანტიო მონაცემების გამართვა</summary>
                    <div class="d-flex flex-wrap gap-1 mt-1">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.warranties.services') }}" wire:navigate>{{ __('warranty.service_directory') }}</a>
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.warranties.policies') }}" wire:navigate>{{ __('warranty.policies_title') }}</a>
                    </div>
                </details>
            </div>
        @endif
    </div>

    <x-warranty.list-card :title="__('warranty.title')" :count="$certificates->total()">
        <x-slot:filters>
            <div class="col-lg-4">
                <label for="warranty-search" class="form-label">{{ __('warranty.search') }}</label>
                <input id="warranty-search" class="form-control" type="search" maxlength="120" wire:model.live.debounce.350ms="search" placeholder="{{ __('warranty.search_placeholder') }}">
            </div>
            <div class="col-md-4 col-lg-2">
                <label for="warranty-status" class="form-label">{{ __('warranty.status') }}</label>
                <select id="warranty-status" class="form-select" wire:model.live="status">
                    <option value="">{{ __('warranty.all_statuses') }}</option>
                    @foreach (['draft', 'issued', 'revoked'] as $value)<option value="{{ $value }}">{{ __('warranty.statuses.'.$value) }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-4 col-lg-2">
                <label for="warranty-supplier" class="form-label">{{ __('warranty.supplier') }}</label>
                <select id="warranty-supplier" class="form-select" wire:model.live="supplierId">
                    <option value="">{{ __('warranty.all_suppliers') }}</option>
                    @foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ \App\Services\Warranties\WarrantySupplierIdentity::displayName($supplier) }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-4 col-lg-2">
                <label for="warranty-from" class="form-label">{{ __('warranty.delivery_from') }}</label>
                <input id="warranty-from" class="form-control" type="date" wire:model.live="from">
            </div>
            <div class="col-md-4 col-lg-2">
                <label for="warranty-to" class="form-label">{{ __('warranty.delivery_to') }}</label>
                <input id="warranty-to" class="form-control" type="date" wire:model.live="to">
            </div>
        </x-slot:filters>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>{{ __('warranty.number') }}</th><th>{{ __('warranty.customer') }}</th><th>{{ __('warranty.supplier') }}</th><th>{{ __('warranty.delivery_date') }}</th><th>{{ __('warranty.status') }}</th><th></th></tr></thead>
                <tbody>
                    @forelse ($certificates as $certificate)
                        @php
                            $badge = match ($certificate->status) { 'issued' => 'success', 'revoked' => 'danger', default => 'warning' };
                            $certificateNative = $certificate->status === 'draft'
                                ? app(\App\Services\Warranties\WarrantySupplierIdentity::class)->resolve($certificate->deliveryItem, $certificate)['native_sku']
                                : data_get($certificate->snapshot, 'item.supplier_sku');
                        @endphp
                        <tr wire:key="warranty-certificate-{{ $certificate->id }}">
                            <td><strong>{{ $certificate->number }}</strong><small class="d-block text-muted">{{ $certificate->order?->number }} · {{ __('warranty.revision') }} {{ $certificate->revision }}</small></td>
                            <td>{{ $certificate->customer_name }}</td>
                            <td>{{ \App\Services\Warranties\WarrantySupplierIdentity::displayName($certificate->supplier) ?: __('warranty.supplier_missing') }}<small class="d-block text-muted">{{ __('warranty.supplier_sku') }}: {{ $certificateNative ?: '—' }}</small></td>
                            <td>{{ $certificate->delivery_date?->format('d.m.Y') ?: '—' }}</td>
                            <td><span class="badge badge-light-{{ $badge }}">{{ __('warranty.statuses.'.$certificate->status) }}</span></td>
                            <td class="text-end"><a href="{{ route('admin.warranties.show', $certificate) }}" class="btn btn-sm btn-outline-primary" wire:navigate>{{ __('warranty.open') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted text-center py-3">{{ __('warranty.no_certificates') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($certificates->hasPages())<div class="card-body">{{ $certificates->links() }}</div>@endif
    </x-warranty.list-card>
</div>
