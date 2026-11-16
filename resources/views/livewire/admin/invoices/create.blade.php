<div>
    <form wire:submit="download">
        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">{{ __('invoice.items') }}</h4>
                    </div>

                    <div class="table-responsive">
                        <table class="table mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>{{ __('admin.name') }}</th>
                                    <th style="width:130px">{{ __('admin.sku') }}</th>
                                    <th style="width:90px">{{ __('admin.qty') }}</th>
                                    <th style="width:130px">{{ __('admin.price') }}</th>
                                    <th style="width:50px"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($lines as $at => $line)
                                    <tr wire:key="line-{{ $at }}">
                                        <td>
                                            <input type="text" class="form-control @error('lines.'.$at.'.name') is-invalid @enderror"
                                                   wire:model="lines.{{ $at }}.name">
                                        </td>
                                        <td>
                                            <input type="text" class="form-control" wire:model="lines.{{ $at }}.sku">
                                        </td>
                                        <td>
                                            <input type="number" min="1" class="form-control @error('lines.'.$at.'.qty') is-invalid @enderror"
                                                   wire:model.live.debounce.400ms="lines.{{ $at }}.qty">
                                        </td>
                                        <td>
                                            <input type="text" inputmode="decimal" class="form-control @error('lines.'.$at.'.price') is-invalid @enderror"
                                                   wire:model.live.debounce.400ms="lines.{{ $at }}.price">
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    wire:click="removeLine({{ $at }})" title="{{ __('admin.delete') }}">&times;</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-1">
                        <button type="button" class="btn btn-outline-primary" wire:click="addLine">
                            + {{ __('admin.invoice_add_line') }}
                        </button>

                        <div class="d-flex align-items-center gap-1">
                            <label class="form-label mb-0" for="invShipping">{{ __('checkout.shipping') }}</label>
                            <input type="text" inputmode="decimal" id="invShipping" style="width:120px"
                                   class="form-control @error('shipping') is-invalid @enderror"
                                   wire:model.live.debounce.400ms="shipping" placeholder="0">
                        </div>
                    </div>

                    <div class="card-footer d-flex justify-content-between">
                        <span class="fw-bolder">{{ __('admin.total') }}</span>
                        <span class="fw-bolder">{{ money($total) }}</span>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <div class="row g-1">
                            <div class="col-7">
                                <label class="form-label" for="invNumber">{{ __('admin.order_number') }}</label>
                                <input type="text" id="invNumber" class="form-control @error('number') is-invalid @enderror" wire:model="number">
                            </div>
                            <div class="col-5">
                                <label class="form-label" for="invDate">{{ __('admin.date') }}</label>
                                <input type="date" id="invDate" class="form-control @error('date') is-invalid @enderror" wire:model="date">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">{{ __('invoice.customer') }}</h4>
                    </div>
                    <div class="card-body">
                        <label class="form-label" for="invBuyer">{{ __('admin.name') }}</label>
                        <input type="text" id="invBuyer" class="form-control @error('buyerName') is-invalid @enderror" wire:model="buyerName">
                        @error('buyerName') <div class="invalid-feedback">{{ $message }}</div> @enderror

                        <label class="form-label mt-1" for="invTax">{{ __('invoice.tax_id') }}</label>
                        <input type="text" id="invTax" class="form-control" wire:model="buyerTaxId">

                        <label class="form-label mt-1" for="invAccount">{{ __('invoice.iban') }}</label>
                        <input type="text" id="invAccount" class="form-control @error('buyerAccount') is-invalid @enderror"
                               wire:model="buyerAccount" placeholder="GE00TB0000000000000000">

                        <label class="form-label mt-1" for="invPhone">{{ __('checkout.phone') }}</label>
                        <input type="text" id="invPhone" class="form-control" wire:model="phone">

                        <label class="form-label mt-1" for="invEmail">{{ __('checkout.email') }}</label>
                        <input type="email" id="invEmail" class="form-control @error('email') is-invalid @enderror" wire:model="email">

                        <label class="form-label mt-1" for="invAddress">{{ __('checkout.address') }}</label>
                        <input type="text" id="invAddress" class="form-control" wire:model="address">
                    </div>
                </div>

                @if ($errors->any())
                    <div class="alert alert-danger p-1">{{ $errors->first() }}</div>
                @endif

                <button type="submit" class="btn btn-primary w-100" wire:loading.attr="disabled" wire:target="download">
                    {{ __('admin.invoice_download') }}
                </button>
            </div>
        </div>
    </form>
</div>
