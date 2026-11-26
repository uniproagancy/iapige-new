@can('admin')
    @if (app(\App\Services\SalesChat\SalesChatSettings::class)->enabled())
        @php($salesChatUnread = \App\Models\SalesChatConversation::inInbox()->unreadFor()->count())
        <li class="nav-item {{ $on('admin.sales-chat.index', 'admin.sales-chat.leads') }}">
            <a class="nav-link d-flex align-items-center" href="{{ route('admin.sales-chat.index') }}">
                {!! $icon('phone') !!}<span>{{ __('sales_chat_admin.title') }}</span>
                <span class="badge rounded-pill badge-light-warning ms-50{{ $salesChatUnread ? '' : ' d-none' }}" data-sales-chat-unread>{{ $salesChatUnread }}</span>
            </a>
        </li>
    @endif
@endcan

@can('owner')
    <li class="nav-item {{ $on('admin.sales-chat.setup.*') }}">
        <a class="nav-link d-flex align-items-center" href="{{ route('admin.sales-chat.setup.show') }}">
            {!! $icon('phone') !!}<span>{{ __(app(\App\Services\SalesChat\SalesChatSettings::class)->enabled() ? 'sales_chat_setup.title' : 'sales_chat_setup.activate') }}</span>
        </a>
    </li>
@endcan
