@php
    $perks = [
        ['truck',        __('home.perk_delivery'),     __('home.perk_delivery_text')],
        ['credit-card',  __('home.perk_warranty'),     __('home.perk_warranty_text')],
        ['phone',  __('home.perk_installments'), __('home.perk_installments_text')],
    ];
@endphp

<section class="perks" aria-label="{{ __('layout.services') }}">
    @foreach ($perks as [$icon, $title, $text])
        <div class="perk">
            <span class="perk__ico"><x-icon :name="$icon" size="22" /></span>
            <span class="perk__title">{{ $title }}</span>
            <span class="perk__text">{{ $text }}</span>
        </div>
    @endforeach
</section>
