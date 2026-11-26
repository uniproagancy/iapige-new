<fieldset class="border rounded p-1" data-example>
    <legend class="visually-hidden">{{ __('sales_chat_knowledge.example') }}</legend>
    <div class="row g-1">
        @foreach (['question', 'answer'] as $key)
            @php
                $field = $fieldIndex === null ? null : 'examples.'.$fieldIndex.'.'.$key;
                $id = 'sales-chat-example-'.$index.'-'.$key;
                $value = is_string($example[$key] ?? null) ? $example[$key] : '';
            @endphp
            <div class="col-12 {{ $key === 'question' ? 'col-lg-5' : 'col-lg-7' }}">
                <label class="form-label" for="{{ $id }}">{{ __('sales_chat_knowledge.'.$key) }}</label>
                @if ($key === 'question')
                    <input class="form-control @error($field) is-invalid @enderror" id="{{ $id }}" name="examples[{{ $index }}][question]"
                           type="text" maxlength="{{ \App\Services\SalesChat\SalesChatKnowledge::QUESTION_LENGTH }}" value="{{ $value }}"
                           placeholder="{{ __('sales_chat_knowledge.question_example') }}"
                           @error($field) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror>
                @else
                    <textarea class="form-control @error($field) is-invalid @enderror" id="{{ $id }}" name="examples[{{ $index }}][answer]"
                              rows="3" maxlength="{{ \App\Services\SalesChat\SalesChatKnowledge::ANSWER_LENGTH }}"
                              @error($field) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror>{{ $value }}</textarea>
                @endif
                @error($field)
                    <div class="invalid-feedback" id="{{ $id }}-error">{{ $message }}</div>
                @enderror
            </div>
        @endforeach
    </div>
    <button type="button" class="btn btn-outline-secondary btn-sm mt-50" data-remove-example>
        {{ __('sales_chat_knowledge.example_remove') }}
    </button>
</fieldset>
