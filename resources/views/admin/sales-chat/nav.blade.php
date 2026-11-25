@can('admin')
    @if (app(\App\Services\SalesChat\SalesChatSettings::class)->enabled())
        @php($salesChatUnread = \App\Models\SalesChatConversation::inInbox()->unreadFor((int) auth()->id())->count())
        <li class="nav-item {{ $on('admin.sales-chat.index', 'admin.sales-chat.leads') }}">
            <a class="nav-link d-flex align-items-center" href="{{ route('admin.sales-chat.index') }}">
                {!! $icon('phone') !!}<span>{{ __('sales_chat_admin.title') }}</span>
                {!! $pill($salesChatUnread, 'warning') !!}
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
