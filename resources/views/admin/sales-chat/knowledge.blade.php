@component('layouts.admin', ['title' => __('sales_chat_knowledge.title')])
    <a class="btn btn-outline-secondary mb-2" href="{{ route('admin.sales-chat.index') }}">
        {{ __('sales_chat_knowledge.back') }}
    </a>

    <section class="card" aria-labelledby="sales-chat-knowledge-title">
        <div class="card-header">
            <h2 id="sales-chat-knowledge-title" class="card-title h5 mb-0">{{ __('sales_chat_knowledge.title') }}</h2>
        </div>
        <div class="card-body">
            @if (session('status'))
                <div class="alert alert-success p-1" role="status">{{ session('status') }}</div>
            @endif

            <p>{{ __('sales_chat_knowledge.intro') }}</p>
            <form method="POST" action="{{ route('admin.sales-chat.knowledge.update') }}">
                @csrf
                <div class="row g-1">
                    @foreach (['ka', 'en'] as $language)
                        @php($field = 'delivery_'.$language)
                        <div class="col-12 col-lg-6">
                            <label class="form-label" for="sales-chat-{{ $field }}">{{ __('sales_chat_knowledge.'.$field) }}</label>
                            <textarea class="form-control @error($field) is-invalid @enderror" id="sales-chat-{{ $field }}"
                                      name="{{ $field }}" lang="{{ $language }}" rows="3" maxlength="1500" required
                                      @error($field) aria-invalid="true" aria-describedby="sales-chat-{{ $field }}-error" @enderror>{{ is_string(old($field)) ? old($field) : $knowledge[$field] }}</textarea>
                            @error($field)
                                <div class="invalid-feedback" id="sales-chat-{{ $field }}-error">{{ $message }}</div>
                            @enderror
                        </div>
                    @endforeach
                    <div class="col-12">
                        <label class="form-label" for="sales-chat-facts">{{ __('sales_chat_knowledge.facts') }}</label>
                        <textarea class="form-control @error('facts') is-invalid @enderror" id="sales-chat-facts" name="facts"
                                  rows="8" maxlength="6000" aria-describedby="sales-chat-facts-help @error('facts') sales-chat-facts-error @enderror"
                                  @error('facts') aria-invalid="true" @enderror>{{ is_string(old('facts')) ? old('facts') : $knowledge['facts'] }}</textarea>
                        <div class="form-text" id="sales-chat-facts-help">{{ __('sales_chat_knowledge.facts_help') }}</div>
                        @error('facts')
                            <div class="invalid-feedback" id="sales-chat-facts-error">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-1">{{ __('sales_chat_knowledge.save') }}</button>
            </form>
        </div>
    </section>
@endcomponent
