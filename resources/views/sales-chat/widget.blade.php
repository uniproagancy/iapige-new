<link rel="stylesheet" href="{{ asset('sales-chat-invite-20261124.css') }}">
<link rel="stylesheet" href="{{ asset('sales-chat-widget-20261124-ux2.css') }}">

@php
    $chatProductSlug = request()->routeIs('product') ? request()->route('slug') : null;
    $chatSection = match (true) {
        request()->routeIs('product') && is_string($chatProductSlug) => 'product',
        request()->routeIs('catalog') => 'catalog',
        request()->routeIs('checkout') => 'checkout',
        request()->routeIs('home') => 'home',
        default => 'other',
    };
@endphp
<div class="iapi-sales-chat" data-sales-chat
    data-show-url="{{ route('sales-chat.show') }}"
    data-message-url="{{ route('sales-chat.message') }}"
    data-lead-url="{{ route('sales-chat.lead') }}"
    data-csrf="{{ csrf_token() }}"
    data-presence-url="{{ route('sales-chat.presence', [], false) }}"
    data-presence-section="{{ $chatSection }}"
    data-chat-invite-text="{{ __('sales_chat_invite.message') }}"
    data-chat-invite-open="{{ __('sales_chat_invite.open') }}"
    data-chat-invite-close="{{ __('sales_chat_invite.close') }}"
    @if (is_string($chatProductSlug) && preg_match('/\A[\pL\pN][\pL\pN._~-]{0,254}\z/u', $chatProductSlug))
        data-product-slug="{{ $chatProductSlug }}"
    @endif
    data-copy="{{ json_encode(trans('sales_chat.widget') + [
        'phone_error' => __('sales_chat.phone_invalid'),
        'product_error' => __('sales_chat.product_unavailable'),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}">
    <button class="iapi-sales-chat__launcher" type="button" aria-expanded="false"
        aria-controls="iapi-sales-chat-dialog" data-chat-toggle>
        <span class="iapi-sales-chat__icon" aria-hidden="true"><span></span></span>
        {{ __('sales_chat.widget.launcher') }}
    </button>

    <section class="iapi-sales-chat__panel" id="iapi-sales-chat-dialog" role="dialog"
        aria-labelledby="iapi-sales-chat-title" tabindex="-1" hidden data-chat-panel>
        <header class="iapi-sales-chat__header">
            <span class="iapi-sales-chat__mark" aria-hidden="true">
                <span class="iapi-sales-chat__icon"><span></span></span>
            </span>
            <div>
                <h2 id="iapi-sales-chat-title">{{ __('sales_chat.widget.title') }}</h2>
                <p data-chat-mode>{{ __('sales_chat.widget.loading') }}</p>
            </div>
            <button class="iapi-sales-chat__close" type="button"
                aria-label="{{ __('sales_chat.widget.close') }}" data-chat-close>&times;</button>
        </header>

        <div class="iapi-sales-chat__messages" role="log" aria-live="polite"
            aria-relevant="additions" aria-label="{{ __('sales_chat.widget.conversation') }}" data-chat-messages></div>

        <div class="iapi-sales-chat__footer">
            <p class="iapi-sales-chat__notice" role="status" data-chat-notice hidden></p>
            <form class="iapi-sales-chat__contact" data-chat-lead hidden>
                <div class="iapi-sales-chat__field iapi-sales-chat__field--wide">
                    <label for="iapi-sales-chat-product">{{ __('sales_chat.widget.product') }}</label>
                    <select id="iapi-sales-chat-product" name="product" required>
                        <option value="">{{ __('sales_chat.widget.choose_product') }}</option>
                    </select>
                </div>
                <div class="iapi-sales-chat__field">
                    <label for="iapi-sales-chat-name">{{ __('sales_chat.widget.full_name') }}</label>
                    <input id="iapi-sales-chat-name" name="name" type="text" autocomplete="name" maxlength="80">
                </div>
                <div class="iapi-sales-chat__field">
                    <label for="iapi-sales-chat-phone">{{ __('sales_chat.widget.phone') }}</label>
                    <input id="iapi-sales-chat-phone" name="phone" type="tel" autocomplete="tel"
                        maxlength="32" required>
                </div>
                <button class="iapi-sales-chat__primary" type="submit" disabled data-chat-save>
                    {{ __('sales_chat.widget.save_contact') }}
                </button>
            </form>
            <form class="iapi-sales-chat__composer" data-chat-form>
                <label class="iapi-sales-chat__message-label" for="iapi-sales-chat-message">
                    {{ __('sales_chat.widget.message') }}
                </label>
                <textarea id="iapi-sales-chat-message" name="message" rows="1" maxlength="1000"
                    aria-describedby="iapi-sales-chat-privacy" placeholder="{{ __('sales_chat.widget.message_placeholder') }}" required></textarea>
                <button class="iapi-sales-chat__primary" type="submit" disabled data-chat-send>
                    {{ __('sales_chat.widget.send') }}
                </button>
            </form>
            <p class="iapi-sales-chat__privacy" id="iapi-sales-chat-privacy">{{ __('sales_chat.widget.privacy') }}</p>
        </div>
    </section>
</div>

<script src="{{ asset('sales-chat-widget-20261124-ux2.js') }}" defer></script>
<script src="{{ asset('sales-chat-presence-20261124.js') }}" defer></script>
<script src="{{ asset('sales-chat-invite-20261124.js') }}" defer></script>
