@if ($lead?->order_id !== null)
    <span class="badge chat-badge--lead">{{ __('sales_chat_admin.lead_order_created') }}</span>
@elseif ($lead?->status === \App\Models\CallbackRequest::NEW)
    <span class="badge chat-badge--pending">{{ __('sales_chat_admin.lead_waiting_for_call') }}</span>
@elseif ($lead?->status === \App\Models\CallbackRequest::CALLED)
    <span class="badge chat-badge--lead">{{ __('sales_chat_admin.states.called') }}</span>
@elseif ($lead?->status === \App\Models\CallbackRequest::CLOSED)
    <span class="badge chat-badge--operator">{{ __('sales_chat_admin.states.closed') }}</span>
@else
    <span class="badge chat-badge--lead">{{ __('sales_chat_admin.lead_badge') }}</span>
@endif
@if ($lead?->status === \App\Models\CallbackRequest::CALLED)
    <span class="chat-meta small align-self-center">
        @if ($lead->handler)
            {{ __('sales_chat_admin.lead_called_by', ['name' => $lead->handler->name]) }}
        @endif
        @if ($lead->handled_at)
            <time datetime="{{ $lead->handled_at->toIso8601String() }}">{{ $lead->handled_at->format('d.m H:i') }}</time>
        @endif
    </span>
@endif
