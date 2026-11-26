@component('layouts.admin', ['title' => __('sales_chat_knowledge.title')])
    @php
        $draftExamples = old('examples', old('examples_present') ? [] : $knowledge['examples']);
        $draftExamples = array_slice(is_array($draftExamples) ? $draftExamples : [], 0, \App\Services\SalesChat\SalesChatKnowledge::EXAMPLE_LIMIT, true);
        $draftExamples = array_filter($draftExamples, fn ($index) => is_int($index) && $index >= 0, ARRAY_FILTER_USE_KEY);
        $displayExamples = $draftExamples ?: [[]];
        $facts = old('facts', $knowledge['facts']);
    @endphp

    <a class="btn btn-outline-secondary mb-2" href="{{ route('admin.sales-chat.index') }}">
        {{ __('sales_chat_knowledge.back') }}
    </a>

    @if (session('status'))
        <div class="alert alert-success p-1" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger p-1" role="alert">{{ __('sales_chat_knowledge.correct_fields') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.sales-chat.knowledge.update') }}">
        @csrf
        <p>{{ __('sales_chat_knowledge.intro') }}</p>

        <section class="card" aria-labelledby="sales-chat-facts-title">
            <div class="card-header">
                <h2 id="sales-chat-facts-title" class="card-title h5 mb-0">
                    <label for="sales-chat-facts" class="mb-0">{{ __('sales_chat_knowledge.facts') }}</label>
                </h2>
            </div>
            <div class="card-body">
                <textarea class="form-control @error('facts') is-invalid @enderror" id="sales-chat-facts" name="facts"
                          rows="8" maxlength="6000" aria-describedby="sales-chat-facts-help @error('facts') sales-chat-facts-error @enderror"
                          @error('facts') aria-invalid="true" @enderror>{{ is_string($facts) ? $facts : '' }}</textarea>
                <div class="form-text" id="sales-chat-facts-help">{{ __('sales_chat_knowledge.facts_help') }}</div>
                @error('facts')
                    <div class="invalid-feedback" id="sales-chat-facts-error">{{ $message }}</div>
                @enderror
                <button type="submit" class="btn btn-primary mt-1">{{ __('sales_chat_knowledge.save') }}</button>
            </div>
        </section>

        <details class="card" aria-labelledby="sales-chat-examples-title" @if ($errors->has('examples') || $errors->has('examples.*.question') || $errors->has('examples.*.answer')) open @endif>
            <summary class="card-header" style="display: list-item; cursor: pointer">
                <span id="sales-chat-examples-title" class="card-title h5 mb-0">{{ __('sales_chat_knowledge.examples') }}</span>
            </summary>
            <div class="card-body">
                <p>{{ __('sales_chat_knowledge.examples_help') }}</p>
                <input type="hidden" name="examples_present" value="1">
                <div class="d-grid gap-1" id="sales-chat-examples" data-limit="{{ \App\Services\SalesChat\SalesChatKnowledge::EXAMPLE_LIMIT }}" data-next-index="{{ max(array_keys($displayExamples)) + 1 }}">
                    @foreach ($displayExamples as $index => $example)
                        @include('admin.sales-chat.knowledge-example', ['index' => $index, 'example' => is_array($example) ? $example : [], 'fieldIndex' => $index])
                    @endforeach
                </div>
                <template id="sales-chat-example-template">
                    @include('admin.sales-chat.knowledge-example', ['index' => '__index__', 'example' => [], 'fieldIndex' => null])
                </template>
                @error('examples') <div class="text-danger mt-50">{{ $message }}</div> @enderror
                <button type="button" class="btn btn-outline-primary mt-1" id="sales-chat-example-add">
                    {{ __('sales_chat_knowledge.example_add') }}
                </button>
            </div>
        </details>

        <section class="card" aria-labelledby="sales-chat-topics-title">
            <div class="card-header">
                <h2 id="sales-chat-topics-title" class="card-title h5 mb-0">{{ __('sales_chat_knowledge.topics_title') }}</h2>
            </div>
            <div class="card-body row g-1">
                @foreach (\App\Services\SalesChat\SalesChatKnowledge::TOPICS as $topic)
                    @php
                        $field = 'topics.'.$topic;
                        $value = old($field, $knowledge['topics'][$topic] ?? '');
                    @endphp
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="sales-chat-topic-{{ $topic }}">{{ __('sales_chat_knowledge.topics.'.$topic) }}</label>
                        <textarea class="form-control @error($field) is-invalid @enderror" id="sales-chat-topic-{{ $topic }}"
                                  name="topics[{{ $topic }}]" rows="4" maxlength="{{ \App\Services\SalesChat\SalesChatKnowledge::TOPIC_LENGTH }}"
                                  placeholder="{{ __('sales_chat_knowledge.topic_examples.'.$topic) }}"
                                  @error($field) aria-invalid="true" aria-describedby="sales-chat-topic-{{ $topic }}-error" @enderror>{{ is_string($value) ? $value : '' }}</textarea>
                        @error($field)
                            <div class="invalid-feedback" id="sales-chat-topic-{{ $topic }}-error">{{ $message }}</div>
                        @enderror
                    </div>
                @endforeach
            </div>
        </section>

        <section class="card" aria-labelledby="sales-chat-delivery-title">
            <div class="card-header">
                <h2 id="sales-chat-delivery-title" class="card-title h5 mb-0">{{ __('sales_chat_knowledge.delivery_title') }}</h2>
            </div>
            <div class="card-body row g-1">
                @foreach (['ka', 'en'] as $language)
                    @php
                        $field = 'delivery_'.$language;
                        $value = old($field, $knowledge[$field]);
                    @endphp
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="sales-chat-{{ $field }}">{{ __('sales_chat_knowledge.'.$field) }}</label>
                        <textarea class="form-control @error($field) is-invalid @enderror" id="sales-chat-{{ $field }}"
                                  name="{{ $field }}" lang="{{ $language }}" rows="3" maxlength="1500" required
                                  @error($field) aria-invalid="true" aria-describedby="sales-chat-{{ $field }}-error" @enderror>{{ is_string($value) ? $value : '' }}</textarea>
                        @error($field)
                            <div class="invalid-feedback" id="sales-chat-{{ $field }}-error">{{ $message }}</div>
                        @enderror
                    </div>
                @endforeach
            </div>
        </section>

        <button type="submit" class="btn btn-primary">{{ __('sales_chat_knowledge.save') }}</button>
    </form>

    <script>
        (() => {
            const examples = document.getElementById('sales-chat-examples');
            const template = document.getElementById('sales-chat-example-template');
            const add = document.getElementById('sales-chat-example-add');

            function updateAddButton() {
                add.disabled = examples.children.length >= Number(examples.dataset.limit);
            }

            add.addEventListener('click', () => {
                const index = Number(examples.dataset.nextIndex);
                examples.dataset.nextIndex = String(index + 1);
                examples.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__index__', String(index)));
                examples.lastElementChild.querySelector('input').focus();
                updateAddButton();
            });

            examples.addEventListener('click', (event) => {
                const button = event.target.closest('[data-remove-example]');
                if (button) {
                    button.closest('[data-example]').remove();
                    updateAddButton();
                    add.focus();
                }
            });

            updateAddButton();
        })();
    </script>
@endcomponent
