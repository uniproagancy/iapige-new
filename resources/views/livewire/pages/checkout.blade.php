<div>
    <x-page-bar :back="route('catalog')" :title="__('checkout.title')" />

    <main class="page">
        <nav class="crumbs" aria-label="{{ __('catalog.breadcrumbs') }}">
            <a href="{{ route('home') }}">{{ __('common.home') }}</a>
            <x-icon name="caret-right" size="12" />
            <b>{{ __('checkout.title') }}</b>
        </nav>

        <div class="page__head">
            <h1 class="page__title">
                <span class="page__bar" aria-hidden="true"></span>
                {{ __('checkout.title') }}
            </h1>
            @if ($buyNow)
                <span class="page__count">{{ __('checkout.buy_now') }}</span>
            @endif
        </div>

        <form class="checkout" wire:submit="place">

            {{-- ------------------------------------------------ left: the form --}}
            <div class="checkout__main">

                {{-- 1. contacts --}}
                <section class="cosec">
                    <div class="cosec__head">
                        <span class="cosec__step">1</span>
                        <h2 class="cosec__title">{{ __('checkout.contacts') }}</h2>
                    </div>

                    <div class="cosec__body">
                        <div class="cofields">
                            <div class="field">
                                <label class="field__label" for="coName">{{ __('common.name') }}</label>
                                <div class="field__box">
                                    <input id="coName" type="text" wire:model="name" autocomplete="name">
                                </div>
                                @error('name') <span class="ferror">{{ $message }}</span> @enderror
                            </div>

                            <div class="field">
                                <label class="field__label" for="coPhone">{{ __('common.phone') }}</label>
                                <div class="field__box">
                                    <input id="coPhone" type="tel" wire:model="phone"
                                           placeholder="+995 5__ __ __ __" autocomplete="tel">
                                </div>
                                @error('phone') <span class="ferror">{{ $message }}</span> @enderror
                            </div>

                            <div class="field field--wide">
                                <label class="field__label" for="coEmail">
                                    {{ __('common.email') }}
                                    <span class="field__hint">{{ __('checkout.optional') }}</span>
                                </label>
                                <div class="field__box">
                                    <input id="coEmail" type="email" wire:model="email" autocomplete="email">
                                </div>
                                @error('email') <span class="ferror">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        @guest
                            <p class="cosec__note">
                                {{ __('checkout.guest_note') }}
                                <button type="button" data-open="auth">{{ __('auth.login') }}</button>
                            </p>
                        @endguest
                    </div>
                </section>

                {{-- 2. delivery --}}
                <section class="cosec">
                    <div class="cosec__head">
                        <span class="cosec__step">2</span>
                        <h2 class="cosec__title">{{ __('checkout.delivery') }}</h2>
                    </div>

                    <div class="cosec__body">
                        {{-- a returning customer picks an address instead of typing one --}}
                        @if ($addresses->isNotEmpty())
                            <div class="saved">
                                @foreach ($addresses as $saved)
                                    <button type="button"
                                            @class(['saved__item', 'is-on' => $addressId === $saved->id])
                                            wire:click="useAddress({{ $saved->id }})"
                                            wire:key="addr-{{ $saved->id }}">
                                        <b class="saved__label">{{ $saved->label ?: $saved->city?->name }}</b>
                                        <span class="saved__text">{{ $saved->oneLine() }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <div class="cofields">
                            <div class="field">
                                <label class="field__label" for="coCity">{{ __('checkout.city') }}</label>
                                <div class="selectwrap">
                                    <select class="coselect" id="coCity" wire:model.live="cityId">
                                        @foreach ($cities as $city)
                                            @php $fee = $city->feeFor(); @endphp
                                            <option value="{{ $city->id }}">
                                                {{ $city->name }} — {{ $fee > 0 ? money($fee) : __('checkout.free') }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <span class="selectwrap__chev" aria-hidden="true">
                                        <x-icon name="caret-down" size="12" />
                                    </span>
                                </div>
                                @error('cityId') <span class="ferror">{{ $message }}</span> @enderror
                            </div>

                            <div class="field">
                                <label class="field__label" for="coAddress">{{ __('checkout.address') }}</label>
                                <div class="field__box">
                                    <input id="coAddress" type="text" wire:model="address" autocomplete="street-address">
                                </div>
                                @error('address') <span class="ferror">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        @if ($chosenCity = $cities->firstWhere('id', $cityId))
                            <p class="cosec__note">
                                <x-icon name="truck" size="14" />
                                {{ __('checkout.days', ['count' => $chosenCity->days]) }}
                            </p>
                        @endif

                        <div class="field">
                            <label class="field__label" for="coComment">
                                {{ __('checkout.comment') }}
                                <span class="field__hint">{{ __('checkout.optional') }}</span>
                            </label>
                            <textarea class="ftext" id="coComment" rows="3" wire:model="comment"
                                      placeholder="{{ __('checkout.comment_ph') }}"></textarea>
                        </div>

                        @auth
                            <label class="check">
                                <input type="checkbox" wire:model="saveAddress">
                                <span>{{ __('checkout.save_address') }}</span>
                            </label>
                        @endauth
                    </div>
                </section>

                {{-- 3. payment --}}
                <section class="cosec">
                    <div class="cosec__head">
                        <span class="cosec__step">3</span>
                        <h2 class="cosec__title">{{ __('checkout.payment') }}</h2>
                    </div>

                    <div class="cosec__body">
                        <div class="paygrid">
                            @foreach ($methods as $method)
                                <label @class(['paycard', 'is-on' => $payment === $method->code])
                                       wire:key="pay-{{ $method->id }}">
                                    <input type="radio" class="sr-only"
                                           value="{{ $method->code }}" wire:model.live="payment">

                                    @if ($method->logoUrl())
                                        <img class="paycard__logo" src="{{ $method->logoUrl() }}" alt="">
                                    @endif

                                    <span class="paycard__name">{{ $method->name }}</span>

                                    @if ($method->note)
                                        <span class="paycard__note">{{ $method->note }}</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                        @error('payment') <span class="ferror">{{ $message }}</span> @enderror

                        {{-- the term only means anything once instalments are chosen --}}
                        @if ($this->isInstallment())
                            <p class="cosec__note">
                                <x-icon name="info" size="14" />
                                {{ __('checkout.installment_note') }}
                            </p>
                        @endif

                        @if ($this->isOnline())
                            <p class="cosec__note">
                                <x-icon name="credit-card" size="14" />
                                {{ __('checkout.redirect_note') }}
                            </p>
                        @endif
                    </div>
                </section>
            </div>

            {{-- ------------------------------------------------ right: the summary --}}
            <aside class="cosum">
                <h2 class="cosum__title">{{ __('checkout.your_order') }}</h2>

                <div class="cosum__items">
                    @foreach ($lines as $line)
                        <div class="cosum__item" wire:key="sum-{{ $line['id'] }}">
                            <img class="cosum__img" src="{{ $line['img'] }}" alt="" loading="lazy">
                            <span class="cosum__body">
                                <span class="cosum__name">{{ $line['name'] }}</span>
                                <span class="cosum__qty">{{ $line['qty'] }} x {{ money($line['price']) }}</span>
                            </span>
                            <span class="cosum__price">{{ money($line['sum']) }}</span>
                        </div>
                    @endforeach
                </div>

                @if ($buyNow)
                    <div class="cosum__qtyrow">
                        <span>{{ __('checkout.quantity') }}</span>
                        <div class="qty">
                            <button type="button" wire:click="decrement" @disabled($qty <= 1)
                                    aria-label="{{ __('common.decrease') }}">
                                <x-icon name="minus" size="14" />
                            </button>
                            <span>{{ $qty }}</span>
                            <button type="button" wire:click="increment" aria-label="{{ __('common.increase') }}">
                                <x-icon name="plus" size="14" />
                            </button>
                        </div>
                    </div>
                @endif

                <div class="cosum__lines">
                    <div class="cosum__line">
                        <span>{{ __('checkout.subtotal') }}</span>
                        <b>{{ money($subtotal) }}</b>
                    </div>
                    <div class="cosum__line">
                        <span>{{ __('checkout.shipping') }}</span>
                        <b>{{ $shipping > 0 ? money($shipping) : __('checkout.free') }}</b>
                    </div>
                </div>

                <div class="cosum__total">
                    <span>{{ __('checkout.total') }}</span>
                    <b>{{ money($total) }}</b>
                </div>

                <button type="submit" class="cosum__submit" wire:loading.attr="disabled" wire:target="place">
                    <span wire:loading.remove wire:target="place">
                        {{ $this->isOnline() ? __('checkout.pay_now') : __('checkout.place_order') }}
                    </span>
                    <span wire:loading wire:target="place">{{ __('checkout.placing') }}</span>
                </button>

                <p class="cosum__terms">{{ __('checkout.terms_note') }}</p>
            </aside>
        </form>
    </main>
	@include('livewire.pages.checkout-bog-script')
</div>
