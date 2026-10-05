@props([
    'pay'   => \App\Support\Store::pay(),
    'banks' => \App\Support\Store::banks(),
])

<footer class="footer" id="footer">
    <div class="foot">
        <div class="foot__brand">
            <div class="foot__logo">
                <span class="logo__mark" aria-hidden="true"><img src="{{ asset('/img/logo.png') }}"></span>
                <span>
                    <span class="foot__name">იაფი</span>
                    <span class="foot__sub">IAPI.GE</span>
                </span>
            </div>
            <div>
                <a class="foot__phone" href="tel:+995322121028">0322 12 10 28</a>
            </div>
            <div class="foot__social">
                <a href="https://www.facebook.com/profile.php?id=100091503274684" aria-label="Facebook"><x-icon name="facebook-logo" size="16" /></a>
                <a href="https://www.instagram.com/iapi_ge/" aria-label="Instagram"><x-icon name="instagram-logo" size="16" /></a>
            </div>
        </div>
        <nav class="foot__col" aria-label="{{ __('footer.shop') }}">
            <h3>{{ __('footer.shop') }}</h3>
            @foreach (array_slice(\App\Support\Catalog::tree(), 0, 4) as $cat)
                <a href="{{ $cat['url'] }}">{{ $cat['name'] }}</a>
            @endforeach
            <a href="{{ route('catalog') }}">{{ __('nav.products') }}</a>
            <a href="{{ route('home') }}#promo" class="is-hot">{{ __('footer.deals') }}</a>
        </nav>
        <nav class="foot__col" aria-label="{{ __('footer.help') }}">
            <h3>{{ __('footer.help') }}</h3>
            <a href="{{ route('info', 'delivery') }}">{{ __('footer.delivery') }}</a>
            <a href="{{ route('info', 'returns') }}">{{ __('footer.returns') }}</a>
            <a href="{{ route('info', 'warranty') }}">{{ __('footer.warranty') }}</a>
            <a href="{{ route('contact') }}">{{ __('footer.contact') }}</a>
        </nav>
        <div class="foot__pay">
            <span class="foot__label">{{ __('footer.payment') }}</span>
            <div class="pay-list">
                @foreach ($pay as $card)
                    <span class="pay" title="{{ $card['name'] }}">
                        @if (! empty($card['logo']) && file_exists(public_path("img/bank/{$card['logo']}")))
                            <img src="{{ asset("img/bank/{$card['logo']}") }}" alt="{{ $card['name'] }}" loading="lazy">
                        @else
                            <span @class(['pay__text', 'pay__text--italic' => $card['italic'] ?? false])
                                  style="color:{{ $card['color'] }}">{{ $card['name'] }}</span>
                        @endif
                    </span>
                @endforeach
            </div>
            <span class="foot__label" style="margin-top:4px">{{ __('footer.installment_banks') }}</span>
            <div class="banks">
                @foreach ($banks as $bank)
                    <div class="bank">
                        @if (! empty($bank['logo']) && file_exists(public_path("img/bank/{$bank['logo']}")))
                            <img class="bank__logo" src="{{ asset("img/bank/{$bank['logo']}") }}" alt="" loading="lazy" style="width: 40px; border-radius: 10px">
                        @else
                            <span class="bank__mark" style="background:{{ $bank['color'] }}">{{ $bank['mark'] }}</span>
                        @endif
                        <span class="bank__name">{{ $bank['name'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="foot__bottom">
        <span class="foot__copy">© {{ date('Y') }} IAPI · {{ __('footer.rights') }}</span>
        <div class="foot__legal">
            <a href="{{ route('info', 'delivery') }}">{{ __('footer.terms') }}</a>
            <a href="#">{{ __('footer.privacy') }}</a>
            <span>{{ __('footer.address') }}</span>
        </div>
    </div>
</footer>
