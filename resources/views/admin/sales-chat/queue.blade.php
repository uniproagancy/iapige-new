<div class="card chat-queue h-100 mb-0">
    <div class="card-header d-flex justify-content-between gap-1">
        <h3 class="card-title chat-heading h5 mb-0">{{ $leadsOnly ? __('sales_chat_admin.leads') : __('sales_chat_admin.queue') }}</h3>
        <a class="btn btn-sm chat-queue__filter" href="{{ route($inboxRoute, ['conversation' => $selectedId, 'filter' => $activeFilter, 'conversations' => $conversations->currentPage()]) }}">{{ __('sales_chat_admin.refresh') }}</a>
    </div>
    <nav class="d-flex gap-50 flex-wrap px-1 pb-1" aria-label="{{ __('sales_chat_admin.filters_label') }}">
        @foreach (['all', 'unread', 'mine'] as $filter)
            <a class="btn btn-sm chat-queue__filter"
               href="{{ route($inboxRoute, ['filter' => $filter, 'conversation' => $selectedId]) }}"
               @if ($activeFilter === $filter) aria-current="true" @endif>
                {{ __('sales_chat_admin.filters.'.$filter) }}
                @if ($filter === 'unread')
                    <span class="badge chat-badge--pending" aria-live="polite">{{ $unreadCount }}</span>
                @endif
            </a>
        @endforeach
    </nav>
    <div class="chat-queue__list">
        @forelse ($conversations as $conversation)
            @php
                $isSelected = $selectedId === (int) $conversation->id;
                $conversationName = __('sales_chat_admin.conversation', ['id' => $conversation->id]);
                $displayTime = $conversation->latestMessage?->created_at ?? $conversation->created_at;
                $leadName = $conversation->contact_consent_at ? trim((string) $conversation->lead?->name) : '';
                $hasLeadName = $leadName !== '' && !in_array($leadName, [__('sales_chat.guest', [], 'ka'), __('sales_chat.guest', [], 'en')], true);
            @endphp
            <a href="{{ route($inboxRoute, ['conversation' => $conversation->id, 'filter' => $activeFilter, 'conversations' => $conversations->currentPage()]) }}"
               class="chat-queue__item"
               @if ($isSelected) aria-current="true" @endif>
                <div class="chat-queue__identity">
                    <div>
                        <strong class="chat-heading text-break">{{ $hasLeadName ? $leadName : $conversationName }}</strong>
                        @if ($hasLeadName)
                            <div class="chat-meta small">{{ $conversationName }}</div>
                        @endif
                    </div>
                    <time class="chat-meta small" datetime="{{ $displayTime->toIso8601String() }}">{{ $displayTime->format('d.m H:i') }}</time>
                </div>
                @if ($conversation->has_unread || $conversation->assignee || $conversation->callback_request_id)
                    <div class="d-flex flex-wrap gap-50 mt-50">
                        @if ($conversation->has_unread)
                            <span class="badge chat-badge--unread">{{ __('sales_chat_admin.filters.unread') }}</span>
                        @endif
                        @if ($conversation->assignee)
                            <span class="badge chat-badge--operator">{{ $conversation->assignee->name }}</span>
                        @endif
                        @if ($conversation->callback_request_id)
                            @include('admin.sales-chat.lead-status', ['lead' => $conversation->lead])
                        @endif
                    </div>
                @endif
                @if ($conversation->latestMessage)
                    <div class="chat-queue__preview small text-break mt-50">
                        <strong>{{ $conversation->latestMessage->staffUser?->name ?? __('sales_chat_admin.roles.'.$conversation->latestMessage->role) }}</strong>
                        <span>{{ \Illuminate\Support\Str::limit($conversation->latestMessage->redactedBody(), 90) }}</span>
                    </div>
                @endif
            </a>
        @empty
            <p class="p-1 mb-0 chat-meta">{{ __('sales_chat_admin.empty_filters.'.$activeFilter) }}</p>
        @endforelse
    </div>
    @if ($conversations->hasPages())
        <div class="card-body py-1">{{ $conversations->links('pagination::simple-bootstrap-5') }}</div>
    @endif
</div>
