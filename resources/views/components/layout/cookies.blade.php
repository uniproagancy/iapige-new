<div class="cookies" id="cookies" hidden role="region" aria-label="{{ __('cookies.label') }}">
    <div class="cookies__body">
        <span class="cookies__ico" aria-hidden="true"><x-icon name="cookie" /></span>
        <span class="cookies__text">
            <b>{{ __('cookies.title') }}</b>
            <span>{{ __('cookies.text') }} <a href="#">{{ __('cookies.policy') }}</a>.</span>
        </span>
    </div>
    <div class="cookies__actions">
        <button type="button" class="cookies__btn cookies__btn--ghost" data-cookies="necessary">{{ __('cookies.necessary') }}</button>
        <button type="button" class="cookies__btn cookies__btn--primary" data-cookies="all">{{ __('cookies.accept') }}</button>
    </div>
</div>
