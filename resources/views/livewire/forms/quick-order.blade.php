@php
    $formId = 'quick-order-'.$productId;
    $cardMethods = $paymentGroups['card'];
    $installmentMethods = $paymentGroups['installment'];
    $cashMethods = $paymentGroups['cash'];
    $transferMethods = $paymentGroups['transfer'];
@endphp

<section class="qorder" id="{{ $formId }}" data-quick-order="{{ $productId }}"
         aria-label="{{ __('product.quick_order') }}"
         x-data="{ category: $wire.entangle('paymentCategory').live, quantityPending: false }"
         x-on:quick-order-quantity-pending.window="if (Number($event.detail.productId) === {{ $productId }}) quantityPending = true"
         x-on:quick-order-quantity-synced.window="if (Number($event.detail.productId) === {{ $productId }}) quantityPending = false">
    @if ($sent)
        <div class="callback__done" role="status">
            <span class="callback__tick" aria-hidden="true"><x-icon name="check" size="16" /></span>
            <span>
                <b>{{ __('product.quick_saved') }}</b>
                @if (! $orderNumber)
                    <span class="callback__when">{{ __('product.callback_when') }}</span>
                @endif
            </span>
        </div>
        @if ($orderError)
            <p class="qorder__error" role="alert">{{ $orderError }}</p>
        @endif
        @if ($orderNumber)
            <a class="qorder__resume" href="{{ route('order', $orderNumber) }}">
                {{ __('product.quick_resume_order') }}
                <x-icon name="caret-right" size="16" />
            </a>
        @endif
        <button type="button" class="callback__again" wire:click="again" data-quick-order-again>
            {{ __('product.callback_again') }}
        </button>
    @else
        <h3 class="qorder__heading">{{ __('product.quick_order') }}</h3>
        @if (! $productSummary['available'])
            <p class="qorder__unavailable" role="status"><x-icon name="x" size="14" />{{ __('product.not_available') }}</p>
        @endif

        <form wire:submit="send" class="qorder__form">
            <div class="qorder__contacts">
                <div @class(['qorder__field', 'is-bad' => $errors->has('name')])>
                    <label for="{{ $formId }}-name">{{ __('checkout.name') }}</label>
                    <input id="{{ $formId }}-name" type="text" wire:model="name" required maxlength="80"
                           autocomplete="name" placeholder="{{ __('checkout.name') }}" data-quick-order-name
                           aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}"
                           aria-describedby="{{ $formId }}-name-error">
                    <span class="qorder__error" id="{{ $formId }}-name-error" role="alert">@error('name') {{ $message }} @enderror</span>
                </div>
                <div @class(['qorder__field', 'is-bad' => $errors->has('phone')])>
                    <label for="{{ $formId }}-phone">{{ __('product.phone_label') }}</label>
                    <input id="{{ $formId }}-phone" type="tel" inputmode="tel" wire:model="phone" required maxlength="32"
                           autocomplete="tel" placeholder="+995 5XX XX XX XX"
                           aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}"
                           aria-describedby="{{ $formId }}-phone-error">
                    <span class="qorder__error" id="{{ $formId }}-phone-error" role="alert">@error('phone') {{ $message }} @enderror</span>
                </div>
            </div>

            <fieldset class="qorder__payments">
                <legend><x-icon name="credit-card" size="18" />{{ __('product.quick_payment_title') }}</legend>
                <div class="qorder__payment-list">
                    <label @class(['qorder__choice', 'is-unavailable' => $cardMethods->isEmpty()])>
                        <input type="radio" name="{{ $formId }}-category" value="card" x-model="category"
                               @disabled($cardMethods->isEmpty() || ! $productSummary['available']) wire:loading.attr="disabled" wire:target="send">
                        <span class="qorder__icon"><x-icon name="credit-card" size="18" /></span>
                        <span class="qorder__choice-label">{{ __('product.quick_card') }}</span>
                        <span class="qorder__networks" aria-hidden="true">
                            <img src="{{ asset('img/bank/mastercard.svg') }}" alt="" width="28" height="21">
                            <img src="{{ asset('img/bank/visa.webp') }}" alt="" width="37" height="21">
                        </span>
                    </label>

                    <div class="qorder__family" :class="{ 'is-expanded': category === 'installment' }">
                        <label @class(['qorder__choice', 'is-unavailable' => $installmentMethods->isEmpty()])>
                            <input type="radio" name="{{ $formId }}-category" value="installment" x-model="category"
                                   @disabled($installmentMethods->isEmpty() || ! $productSummary['available']) wire:loading.attr="disabled" wire:target="send">
                            <span class="qorder__icon"><x-icon name="list" size="18" /></span>
                            <span class="qorder__choice-label">{{ __('checkout.pay_installment') }}</span>
                            <span class="qorder__meta">
                                @if ($productSummary['monthly'] > 0)
                                    <span class="qorder__monthly">{{ __('product.quick_monthly_from', ['amount' => money($productSummary['monthly'])]) }}</span>
                                @endif
                                <x-icon name="caret-down" size="14" class="qorder__chevron" />
                            </span>
                        </label>
                        <div class="qorder__installments" x-show="category === 'installment'" x-cloak>
                            @foreach ($installmentMethods as $method)
                                @php
                                    $methodLabel = match ($method->code) {
                                        'bog_split' => __('product.quick_bog_split'),
                                        'credo_split' => __('product.quick_credo_split'),
                                        default => __('product.quick_online_installment'),
                                    };
                                @endphp
                                <label class="qorder__choice qorder__bank" wire:key="quick-payment-{{ $productId }}-{{ $method->id }}">
                                    <input type="radio" name="{{ $formId }}-method" value="{{ $method->code }}" wire:model="paymentMethod"
                                           :disabled="category !== 'installment'" aria-label="{{ $method->name }}"
                                           wire:loading.attr="disabled" wire:target="send">
                                    <span class="qorder__bank-logo">
                                        @if ($method->logoUrl())
                                            <img src="{{ $method->logoUrl() }}" alt="{{ $method->name }}" loading="lazy">
                                        @else
                                            <span>{{ $method->name }}</span>
                                        @endif
                                    </span>
                                    <span class="qorder__choice-label">{{ $methodLabel }}</span>
                                    <span class="qorder__check" aria-hidden="true"></span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <label @class(['qorder__choice', 'is-unavailable' => $cashMethods->isEmpty()])>
                        <input type="radio" name="{{ $formId }}-category" value="cash" x-model="category"
                               @disabled($cashMethods->isEmpty() || ! $productSummary['available']) wire:loading.attr="disabled" wire:target="send">
                        <span class="qorder__icon"><x-icon name="truck" size="18" /></span>
                        <span class="qorder__choice-label">{{ __('product.quick_on_delivery') }}</span>
                        <span class="qorder__check" aria-hidden="true"></span>
                    </label>

                    <label @class(['qorder__choice', 'is-unavailable' => $transferMethods->isEmpty()])>
                        <input type="radio" name="{{ $formId }}-category" value="transfer" x-model="category"
                               @disabled($transferMethods->isEmpty() || ! $productSummary['available']) wire:loading.attr="disabled" wire:target="send">
                        <span class="qorder__icon"><x-icon name="scales" size="18" /></span>
                        <span class="qorder__choice-label">{{ __('product.quick_transfer') }}</span>
                        <span class="qorder__check" aria-hidden="true"></span>
                    </label>
                </div>
                @error('paymentCategory') <span class="qorder__error" role="alert">{{ $message }}</span> @enderror
                @error('paymentMethod') <span class="qorder__error" role="alert">{{ $message }}</span> @enderror
            </fieldset>

            @foreach (['card', 'cash', 'transfer'] as $otherCategory)
                @if ($paymentGroups[$otherCategory]->count() > 1)
                <div class="qorder__installments" x-show="category === '{{ $otherCategory }}'" x-cloak>
                    @foreach ($paymentGroups[$otherCategory] as $method)
                        <label class="qorder__choice qorder__bank" wire:key="quick-{{ $otherCategory }}-{{ $productId }}-{{ $method->id }}">
                            <input type="radio" name="{{ $formId }}-method" value="{{ $method->code }}" wire:model="paymentMethod"
                                   :disabled="category !== '{{ $otherCategory }}'" aria-label="{{ $method->name }}">
                            <span class="qorder__choice-label">{{ $method->name }}</span><span class="qorder__check" aria-hidden="true"></span>
                        </label>
                    @endforeach
                </div>
                @endif
            @endforeach

            @error('productId') <span class="qorder__error" role="alert">{{ $message }}</span> @enderror
            @error('quantity') <span class="qorder__error" role="alert">{{ $message }}</span> @enderror
            @error('form') <span class="qorder__error" role="alert">{{ $message }}</span> @enderror

            <div class="qorder__footer">
                <button type="button" class="qorder__cart" aria-label="{{ __('cart.add') }}"
                        wire:click="$dispatch('cart-add', { productId: {{ $productId }}, qty: {{ $quantity }} })"
                        @disabled(! $productSummary['available'])
                        :disabled="quantityPending || {{ $productSummary['available'] ? 'false' : 'true' }}" wire:loading.class="is-loading">
                    <x-icon name="shopping-bag" size="18" />
                </button>
                <button type="submit" class="qorder__submit" @disabled(! $productSummary['available'])
                        :disabled="quantityPending || {{ $productSummary['available'] ? 'false' : 'true' }}" wire:loading.class="is-loading">
                    <span wire:loading.remove wire:target="send">
                        @if ($productSummary['available'])
                        <span x-show="category === 'card' || category === 'installment'">{{ __('product.quick_continue_bank') }}</span>
                        <span x-show="category !== 'card' && category !== 'installment'">{{ __('product.quick_send') }}</span>
                        @else
                            {{ __('product.not_available') }}
                        @endif
                    </span>
                    <span wire:loading wire:target="send">{{ __('common.wait') }}</span>
                    <x-icon name="caret-right" size="16" />
                </button>
            </div>
        </form>
    @endif
</section>
