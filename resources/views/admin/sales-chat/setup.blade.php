@component('layouts.admin', ['title' => __('sales_chat_setup.title')])
    <div class="row">
        <div class="col-12 col-lg-8 col-xl-6">
            <section class="card" aria-labelledby="sales-chat-setup-title">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-1">
                    <h2 id="sales-chat-setup-title" class="card-title h5 mb-0">{{ __('sales_chat_setup.title') }}</h2>
                    <span class="badge {{ $enabled ? 'bg-success' : 'bg-secondary' }}">
                        {{ __($enabled ? 'sales_chat_setup.enabled' : 'sales_chat_setup.inactive') }}
                    </span>
                </div>

                <div class="card-body">
                    @if (session('status'))
                        <div class="alert alert-success p-1" role="status">{{ session('status') }}</div>
                    @endif
                    @if ($errors->any())
                        <div class="alert alert-danger p-1" role="alert">{{ $errors->first() }}</div>
                    @endif

                    <form method="POST" action="{{ route('admin.sales-chat.setup.activate', [], false) }}">
                        @csrf
                        <label class="form-label" for="sales-chat-openai-key">{{ __('sales_chat_setup.openai_key') }}</label>
                        <input type="password" id="sales-chat-openai-key" name="password" class="form-control"
                               autocomplete="new-password" spellcheck="false" autocapitalize="off" maxlength="512"
                               aria-describedby="sales-chat-key-help" @unless ($hasKey) required @endunless>
                        <div class="form-text" id="sales-chat-key-help">
                            {{ __($hasKey ? 'sales_chat_setup.key_saved' : 'sales_chat_setup.key_help') }}
                        </div>
                        <button type="submit" class="btn btn-primary mt-1">
                            {{ __($enabled ? 'sales_chat_setup.save' : 'sales_chat_setup.activate') }}
                        </button>
                    </form>

                    @if ($enabled)
                        <form method="POST" action="{{ route('admin.sales-chat.setup.disable', [], false) }}" class="mt-1">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary">{{ __('sales_chat_setup.disable') }}</button>
                        </form>
                    @endif
                </div>
            </section>
        </div>
    </div>
@endcomponent
