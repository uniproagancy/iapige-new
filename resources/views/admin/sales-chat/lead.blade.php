@if ($selected->lead && $selected->contact_consent_at)
    @php($leadName = trim((string) $selected->lead->name))
    <div class="chat-lead mx-1 mb-1 rounded p-1">
        <h4 class="chat-heading h6 mb-50">{{ __('sales_chat_admin.lead_title') }}</h4>
        @if ($leadName !== '' && !in_array($leadName, [__('sales_chat.guest', [], 'ka'), __('sales_chat.guest', [], 'en')], true))
            <p class="chat-lead__name fw-bold text-break mb-50">{{ $leadName }}</p>
        @endif
        <div class="d-flex flex-wrap gap-1 align-items-center">
            <a href="tel:{{ preg_replace('/[^+0-9]/', '', $selected->lead->phone) }}">{{ $selected->lead->phone }}</a>
            @if ($selected->lead->product?->slug)
                <a href="{{ route('product', ['slug' => $selected->lead->product->slug]) }}">{{ $selected->lead->product_name ?: $selected->lead->product->name }}</a>
            @elseif ($selected->lead->product_name)
                <span>{{ $selected->lead->product_name }}</span>
            @endif
            @if ($selected->lead->order_id !== null)
                <a class="btn btn-outline-light btn-sm" data-chat-order-action
                   href="{{ route('admin.orders.show', ['order' => $selected->lead->order_id]) }}">
                    {{ __('sales_chat_admin.lead_open_order') }}
                </a>
            @elseif ($selected->lead->page === 'sales-chat')
                <a class="btn btn-primary btn-sm" data-chat-order-action
                   href="{{ route('admin.orders.create', ['chat_lead' => $selected->lead->id]) }}">
                    {{ __('sales_chat_admin.lead_create_order') }}
                </a>
            @endif
            @if ($selected->lead->status === \App\Models\CallbackRequest::NEW
                && $selected->lead->order_id === null && $selected->lead->page === 'sales-chat')
                <form method="POST" action="{{ route('admin.sales-chat.called', $selected) }}">
                    @csrf
                    <input type="hidden" name="leads" value="{{ (int) $leadsOnly }}">
                    <input type="hidden" name="filter" value="{{ $activeFilter }}">
                    <button type="submit" class="btn btn-outline-light btn-sm">{{ __('sales_chat_admin.lead_mark_called') }}</button>
                </form>
            @endif
            @include('admin.sales-chat.lead-status', ['lead' => $selected->lead])
        </div>
        @if ($selected->lead->page === 'sales-chat' && filled($selected->lead->comment))
            <p class="chat-meta small mt-1 mb-0" id="sales-chat-lead-context-label">{{ __('sales_chat_admin.lead_context') }}</p>
            <div class="text-break" aria-labelledby="sales-chat-lead-context-label" style="white-space: pre-wrap">{{ $selected->lead->comment }}</div>
        @endif
    </div>
@endif
