<section class="consult">
    <div class="consult__left">
        <span class="consult__mark" style="background: none" aria-hidden="true"><img src="{{ asset('/img/logo.png') }}"></span>
        <span>
            <span class="consult__t">{{ __('product.consult_title') }}</span>
            <span class="consult__s">{{ __('product.consult_text') }}</span>
        </span>
    </div>
    <livewire:forms.callback-form variant="bar" />
</section>
