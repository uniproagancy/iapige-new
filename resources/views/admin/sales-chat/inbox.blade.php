@component('layouts.admin', ['title' => __('sales_chat_admin.title')])
    <div class="d-flex flex-wrap gap-1 mb-2">
        <a class="btn {{ $leadsOnly ? 'btn-outline-primary' : 'btn-primary' }}" href="{{ route('admin.sales-chat.index') }}">{{ __('sales_chat_admin.all') }}</a>
        <a class="btn {{ $leadsOnly ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('admin.sales-chat.leads') }}">{{ __('sales_chat_admin.leads') }}</a>
        <a class="btn btn-outline-secondary" href="{{ route('admin.sales-chat.presence.show') }}">{{ __('sales_chat_presence.title') }}</a>
        <a class="btn btn-outline-secondary" href="{{ route('admin.sales-chat.knowledge.show') }}">{{ __('sales_chat_knowledge.title') }}</a>
        <button type="button" class="btn btn-outline-secondary" id="sales-chat-sound" aria-pressed="true"
                data-sound-user="{{ auth()->id() }}"
                data-sound-on="{{ __('sales_chat_admin.sound_enabled') }}"
                data-sound-off="{{ __('sales_chat_admin.sound_disabled') }}"
                data-sound-error="{{ __('sales_chat_admin.sound_error') }}">
            {{ __('sales_chat_admin.sound') }}
            <span class="badge rounded-pill bg-success ms-25" id="sales-chat-sound-state" aria-hidden="true">{{ __('sales_chat_admin.sound_enabled') }}</span>
        </button>
        <span class="small text-muted align-self-center" id="sales-chat-sound-error" role="status"></span>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger p-1" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="row g-2 sales-chat-inbox">
        <aside class="col-12 col-xl-4" id="sales-chat-queue"
               data-latest-visitor-id="{{ $latestVisitorId ?? 0 }}"
               data-queue-url="{{ route('admin.sales-chat.queue', ['conversation' => $selectedId, 'filter' => $activeFilter, 'conversations' => $conversations->currentPage(), 'leads' => (int) $leadsOnly]) }}">
            @include('admin.sales-chat.queue')
        </aside>

        <div class="col-12 col-xl-8">
            <section class="card chat-transcript mb-0" aria-label="{{ __('sales_chat_admin.transcript') }}">
                @if (!$selected)
                    <div class="card-body chat-meta">{{ __('sales_chat_admin.choose') }}</div>
                @else
                    <div class="card-header d-flex flex-wrap justify-content-between gap-1">
                        <div>
                            <h3 class="card-title chat-heading h5 mb-25">{{ __('sales_chat_admin.conversation', ['id' => $selected->id]) }}</h3>
                            @if ($selected->assignee)
                                <div class="small chat-meta">{{ __('sales_chat_admin.handled_by', ['name' => $selected->assignee->name]) }}</div>
                            @endif
                        </div>
                        @if (!$selected->assigned_to)
                            <form method="POST" action="{{ route('admin.sales-chat.claim', $selected) }}">
                                @csrf
                                <input type="hidden" name="leads" value="{{ (int) $leadsOnly }}">
                                <input type="hidden" name="filter" value="{{ $activeFilter }}">
                                <button type="submit" class="btn btn-primary btn-sm">{{ __('sales_chat_admin.claim') }}</button>
                            </form>
                        @endif
                    </div>
                    <div id="sales-chat-selected-lead" aria-live="polite">
                        @include('admin.sales-chat.lead')
                    </div>
                    <div class="card-body pt-0">
                        @if ($messages->hasPages())
                            {{ $messages->links('pagination::simple-bootstrap-5') }}
                        @endif
                        <div class="vstack gap-1 overflow-auto p-50" style="max-height: 55vh"
                             id="sales-chat-transcript" aria-live="polite"
                             @if ($messages->onFirstPage())
                                 data-poll-url="{{ route('admin.sales-chat.messages', $selected) }}"
                                 data-read-url="{{ route('admin.sales-chat.read', $selected) }}"
                             @endif>
                            @forelse ($messages->getCollection()->reverse() as $message)
                                <article class="chat-message rounded p-1 {{ $message->role === \App\Models\SalesChatMessage::VISITOR ? 'chat-message--visitor me-xl-4' : 'ms-xl-4' }}" data-message-id="{{ $message->id }}">
                                    <div class="d-flex flex-wrap justify-content-between gap-50 small mb-50">
                                        <strong>{{ $message->staffUser?->name ?? __('sales_chat_admin.roles.'.$message->role) }}</strong>
                                        <time class="chat-meta">{{ $message->created_at->format('d.m H:i') }}</time>
                                    </div>
                                    @php($phone = $message->callbackPhone($selected, $callablePhone))
                                    <div class="text-break" style="white-space: pre-wrap">@if ($phone){{ $phone['before'] }}<a class="chat-phone" href="tel:{{ $phone['number'] }}">{{ $phone['number'] }}</a>{{ $phone['after'] }}@else{{ $message->redactedBody() }}@endif</div>
                                    @foreach ($message->cards ?? [] as $card)
                                        @if (is_string($card['name'] ?? null))
                                            <div class="chat-meta small mt-50">{{ $card['name'] }}</div>
                                        @endif
                                    @endforeach
                                </article>
                            @empty
                                <p class="small chat-meta" id="sales-chat-empty">{{ __('sales_chat_admin.no_messages') }}</p>
                            @endforelse
                        </div>

                        <div id="sales-chat-callback-phone" class="mt-1 p-50 rounded chat-lead chat-meta" @if (!$callablePhone) hidden @endif>
                            {{ __('sales_chat.widget.phone') }}
                            <a class="chat-phone ms-25" @if ($callablePhone) href="tel:{{ $callablePhone }}" @endif>{{ $callablePhone }}</a>
                        </div>

                        @if ($canReply)
                            <form method="POST" action="{{ route('admin.sales-chat.reply', $selected) }}" class="mt-1">
                                @csrf
                                <input type="hidden" name="leads" value="{{ (int) $leadsOnly }}">
                                <input type="hidden" name="filter" value="{{ $activeFilter }}">
                                <input type="hidden" name="client_message_id" value="{{ old('client_message_id', $replyId) }}">
                                <label class="form-label" for="sales-chat-reply">{{ __('sales_chat_admin.reply') }}</label>
                                <textarea class="form-control" id="sales-chat-reply" name="body" rows="3" required maxlength="2000">{{ old('body') }}</textarea>
                                <button type="submit" class="btn btn-primary mt-1">{{ __('sales_chat_admin.send') }}</button>
                            </form>
                        @endif
                    </div>
                @endif
            </section>
        </div>
    </div>

    @push('styles')
        <style>
            .sales-chat-inbox {
                --chat-surface: #242d43;
                --chat-row: #20283d;
                --chat-hover: #2b3652;
                --chat-selected: #354069;
                --chat-border: #465473;
                --chat-heading: #f4f6ff;
                --chat-text: #e3e9f9;
                --chat-meta: #c5cfe5;
                --chat-amber: #ffc04d;
                --chat-dark-ink: #261700;
            }

            .sales-chat-inbox :is(.chat-queue, .chat-transcript) {
                background: var(--chat-surface);
                color: var(--chat-text);
                border: 1px solid var(--chat-border);
            }
            .sales-chat-inbox .chat-heading { color: var(--chat-heading); }
            .sales-chat-inbox .chat-meta { color: var(--chat-meta); }
            .sales-chat-inbox .chat-queue__list { border-top: 1px solid var(--chat-border); }
            .sales-chat-inbox .chat-queue__item {
                display: block;
                padding: .85rem 1rem;
                border-inline-start: 4px solid transparent;
                border-bottom: 1px solid var(--chat-border);
                background: var(--chat-row);
                color: var(--chat-text);
                text-decoration: none;
            }
            .sales-chat-inbox .chat-queue__item:hover { background: var(--chat-hover); }
            .sales-chat-inbox .chat-queue__item[aria-current="true"] {
                background: var(--chat-selected);
                border-inline-start-color: var(--chat-amber);
            }
            .sales-chat-inbox :is(.chat-queue__item, .chat-queue__filter):focus-visible {
                outline: 2px solid var(--chat-amber);
                outline-offset: -3px;
            }
            .sales-chat-inbox .chat-queue__identity {
                display: flex;
                justify-content: space-between;
                flex-wrap: wrap;
                align-items: start;
                gap: .25rem .75rem;
            }
            .sales-chat-inbox .chat-queue__identity > div { min-width: 0; }
            .sales-chat-inbox .chat-queue__identity time { white-space: nowrap; }
            .sales-chat-inbox .chat-queue__preview { color: var(--chat-text); line-height: 1.45; }
            .sales-chat-inbox .chat-queue__filter {
                border-color: #6b7898;
                background: var(--chat-row);
                color: var(--chat-heading);
            }
            .sales-chat-inbox .chat-queue__filter:hover { background: var(--chat-hover); }
            .sales-chat-inbox .chat-queue__filter[aria-current="true"] {
                border-color: #a5b4ee;
                background: var(--chat-selected);
                color: var(--chat-heading);
            }
            .sales-chat-inbox .chat-badge--pending {
                background: var(--chat-amber);
                color: var(--chat-dark-ink);
                font-weight: 700;
            }
            .sales-chat-inbox .chat-badge--unread { background: #c0b8ff; color: #241b54; }
            .sales-chat-inbox .chat-badge--operator { background: #d6def0; color: #24304e; }
            .sales-chat-inbox .chat-badge--lead { background: #a9e6bc; color: #163623; }
            .sales-chat-inbox :is(.chat-message, .chat-lead) {
                background: var(--chat-row);
                color: var(--chat-text);
                border: 1px solid var(--chat-border);
            }
            .sales-chat-inbox .chat-message--visitor { background: var(--chat-hover); }
            .sales-chat-inbox .chat-lead__name { color: var(--chat-heading); }
            .sales-chat-inbox .chat-lead a { color: #ffd893; text-decoration: underline; text-underline-offset: .15em; }
            .sales-chat-inbox .chat-phone { color: #ffd893; font-weight: 700; text-decoration: underline; text-underline-offset: .15em; }
        </style>
    @endpush

    @push('scripts')
        <script>
            (() => {
                const queue = document.getElementById('sales-chat-queue');
                const selectedLead = document.getElementById('sales-chat-selected-lead');
                const soundButton = document.getElementById('sales-chat-sound');
                const soundState = document.getElementById('sales-chat-sound-state');
                const soundError = document.getElementById('sales-chat-sound-error');
                const soundPreferenceKey = 'iapi.salesChat.sound.' + soundButton.dataset.soundUser;
                let rendered = queue.innerHTML.trim();
                let renderedLead = selectedLead?.innerHTML.trim();
                let refreshing = false;
                let latestVisitorId = Number(queue.dataset.latestVisitorId) || 0;
                let timer;
                let audio;
                let soundEnabled = loadSoundPreference();
                let soundPending = false;

                function loadSoundPreference() {
                    try {
                        return window.localStorage.getItem(soundPreferenceKey) !== 'off';
                    } catch {
                        return true;
                    }
                }

                function schedule() {
                    window.clearInterval(timer);
                    timer = window.setInterval(refresh, soundEnabled ? 6000 : 12000);
                }

                function playSound() {
                    if (!soundEnabled || !soundPending || audio?.state !== 'running') return;
                    soundPending = false;
                    try {
                        const time = audio.currentTime;
                        const tone = audio.createOscillator();
                        const volume = audio.createGain();
                        tone.frequency.setValueAtTime(620, time);
                        tone.frequency.setValueAtTime(780, time + 0.10);
                        volume.gain.setValueAtTime(0.0001, time);
                        volume.gain.exponentialRampToValueAtTime(0.14, time + 0.02);
                        volume.gain.exponentialRampToValueAtTime(0.0001, time + 0.25);
                        tone.connect(volume);
                        volume.connect(audio.destination);
                        tone.onended = () => volume.disconnect();
                        tone.start(time);
                        tone.stop(time + 0.26);
                    } catch {
                        // A browser can temporarily suspend audio while the page is in the background.
                        soundPending = true;
                    }
                }

                async function unlockSound(showError = false) {
                    if (!soundEnabled) return;
                    try {
                        const AudioContext = window.AudioContext || window.webkitAudioContext;
                        audio ??= new AudioContext();
                        if (audio.state !== 'running') await audio.resume();
                        if (soundEnabled && audio.state === 'running') {
                            soundError.textContent = '';
                            playSound();
                        } else if (soundEnabled && showError) {
                            soundError.textContent = soundButton.dataset.soundError;
                        }
                    } catch {
                        if (soundEnabled && showError) soundError.textContent = soundButton.dataset.soundError;
                    }
                }

                function syncSound() {
                    soundButton.setAttribute('aria-pressed', String(soundEnabled));
                    soundState.textContent = soundEnabled ? soundButton.dataset.soundOn : soundButton.dataset.soundOff;
                    soundState.classList.toggle('bg-success', soundEnabled);
                    soundState.classList.toggle('bg-secondary', !soundEnabled);
                    schedule();
                }

                soundButton.addEventListener('click', () => {
                    soundEnabled = !soundEnabled;
                    soundPending = false;
                    soundError.textContent = '';
                    try {
                        window.localStorage.setItem(soundPreferenceKey, soundEnabled ? 'on' : 'off');
                    } catch {
                        // The preference still applies to the current page if storage is unavailable.
                    }
                    syncSound();
                    if (soundEnabled) {
                        void unlockSound(true);
                        void refresh();
                    }
                });

                function retryAudio(event) {
                    if (!soundButton.contains(event.target) && audio?.state !== 'running') {
                        void unlockSound(true);
                    }
                }
                document.addEventListener('pointerdown', retryAudio);
                document.addEventListener('keydown', retryAudio);

                window.addEventListener('storage', event => {
                    if (event.key !== soundPreferenceKey) return;
                    soundEnabled = event.newValue !== 'off';
                    soundPending = false;
                    soundError.textContent = '';
                    syncSound();
                    if (soundEnabled) void unlockSound();
                });

                async function refresh() {
                    if (refreshing || (!soundEnabled && (document.hidden || queue.contains(document.activeElement)))) return;
                    refreshing = true;
                    try {
                        const response = await fetch(queue.dataset.queueUrl, {headers: {Accept: 'application/json'}, cache: 'no-store'});
                        if (!response.ok) return;
                        const {html, leadHtml, latestVisitorId: incoming} = await response.json();
                        const nextVisitorId = Number(incoming);
                        if (Number.isSafeInteger(nextVisitorId) && nextVisitorId > latestVisitorId) {
                            latestVisitorId = nextVisitorId;
                            if (soundEnabled) {
                                soundPending = true;
                                playSound();
                            }
                        }
                        if (typeof html === 'string' && html.trim() !== rendered
                            && !document.hidden && !queue.contains(document.activeElement)) {
                            rendered = html.trim();
                            queue.innerHTML = rendered;
                        }
                        if (selectedLead && typeof leadHtml === 'string' && leadHtml.trim() !== renderedLead
                            && !document.hidden && !selectedLead.contains(document.activeElement)) {
                            renderedLead = leadHtml.trim();
                            selectedLead.innerHTML = renderedLead;
                        }
                    } catch {
                        // Retry transient fetch failures on the next poll.
                    } finally {
                        refreshing = false;
                    }
                }

                document.addEventListener('sales-chat:marked-read', refresh);
                document.addEventListener('visibilitychange', () => {
                    if (document.hidden) return;
                    void unlockSound();
                    void refresh();
                });
                syncSound();
                void unlockSound();
            })();
        </script>
    @endpush

    @if ($selected && $messages->onFirstPage())
        @push('scripts')
            <script>
                (() => {
                    const transcript = document.getElementById('sales-chat-transcript');
                    const url = new URL(transcript.dataset.pollUrl, window.location.href);
                    const csrf = document.querySelector('meta[name="csrf-token"]').content;
                    const phoneFooter = document.getElementById('sales-chat-callback-phone');
                    const phoneLink = phoneFooter?.querySelector('a');
                    let after = Number(transcript.lastElementChild?.dataset.messageId || 0);
                    let acknowledged = 0;

                    function isCallbackPhone(value) {
                        return typeof value === 'string' && /^\+9955\d{8}$/.test(value);
                    }

                    function append(message) {
                        const row = document.createElement('article');
                        row.className = `chat-message rounded p-1 ${message.role === 'visitor' ? 'chat-message--visitor me-xl-4' : 'ms-xl-4'}`;
                        row.dataset.messageId = message.id;
                        const header = document.createElement('div');
                        header.className = 'd-flex flex-wrap justify-content-between gap-50 small mb-50';
                        const author = document.createElement('strong');
                        author.textContent = message.author;
                        const time = document.createElement('time');
                        time.className = 'chat-meta';
                        time.textContent = message.time;
                        const body = document.createElement('div');
                        body.className = 'text-break';
                        body.style.whiteSpace = 'pre-wrap';
                        const phone = message.callback_phone;
                        if (isCallbackPhone(phone?.number)) {
                            const link = document.createElement('a');
                            link.className = 'chat-phone';
                            link.href = `tel:${phone.number}`;
                            link.textContent = phone.number;
                            body.append(phone.before, link, phone.after);
                        } else {
                            body.textContent = message.body;
                        }
                        header.append(author, time);
                        row.append(header, body);
                        for (const card of message.cards || []) {
                            if (typeof card?.name !== 'string') continue;
                            const title = document.createElement('div');
                            title.className = 'chat-meta small mt-50';
                            title.textContent = card.name;
                            row.append(title);
                        }
                        transcript.append(row);
                    }

                    async function refresh() {
                        try {
                            if (document.hidden) return;
                            url.searchParams.set('after', after);
                            const response = await fetch(url, {headers: {Accept: 'application/json'}, cache: 'no-store'});
                            if (!response.ok) return;
                            const data = await response.json();
                            if (document.hidden) return;
                            if (phoneFooter && phoneLink) {
                                const phone = data.callback_phone;
                                phoneFooter.hidden = !isCallbackPhone(phone);
                                if (phoneFooter.hidden) {
                                    phoneLink.removeAttribute('href');
                                    phoneLink.textContent = '';
                                } else if (phoneLink.textContent !== phone) {
                                    phoneLink.href = `tel:${phone}`;
                                    phoneLink.textContent = phone;
                                }
                            }
                            const follow = transcript.scrollHeight - transcript.scrollTop - transcript.clientHeight < 72;
                            for (const message of data.messages) {
                                if (message.id <= after) continue;
                                document.getElementById('sales-chat-empty')?.remove();
                                append(message);
                                after = message.id;
                            }
                            if (follow) transcript.scrollTop = transcript.scrollHeight;
                            if (after > acknowledged) {
                                const receipt = await fetch(transcript.dataset.readUrl, {
                                    method: 'POST',
                                    headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                                    body: JSON.stringify({through: after}),
                                });
                                if (receipt.ok) {
                                    acknowledged = after;
                                    document.dispatchEvent(new Event('sales-chat:marked-read'));
                                }
                            }
                        } catch {
                            // Retry transient fetch failures on the next poll.
                        } finally {
                            window.setTimeout(refresh, 6000);
                        }
                    }

                    transcript.scrollTop = transcript.scrollHeight;
                    refresh();
                })();
            </script>
        @endpush
    @endif
@endcomponent
