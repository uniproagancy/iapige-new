@component('layouts.admin', ['title' => __('sales_chat_presence.title')])
    <div class="sales-presence" id="sales-presence" data-url="{{ $dataUrl }}">
        <header class="presence-header mb-2">
            <div>
                <h1 class="h3 mb-25">{{ __('sales_chat_presence.title') }}</h1>
                <p class="text-muted mb-0" data-presence-window>{{ __('sales_chat_presence.window', ['seconds' => $presence['window_seconds']]) }}</p>
            </div>
            <a class="btn btn-outline-primary" href="{{ route('admin.sales-chat.index') }}">{{ __('sales_chat_presence.inbox') }}</a>
        </header>

        <section class="card presence-summary mb-2" aria-labelledby="presence-total-label">
            <div class="card-body presence-summary__body">
                <div>
                    <h2 class="h6 mb-50" id="presence-total-label">{{ __('sales_chat_presence.active') }}</h2>
                    <strong class="presence-total" data-presence-total aria-live="polite">{{ $presence['active_count'] }}</strong>
                </div>
                <div class="presence-summary__side">
                    <p class="presence-status mb-25" data-presence-status role="status">{{ __('sales_chat_presence.live') }}</p>
                    <p class="text-muted small mb-0" data-presence-updated></p>
                </div>
            </div>
        </section>

        <div class="presence-columns mb-2">
            <section class="card mb-0" aria-labelledby="presence-products-label">
                <div class="card-body">
                    <h2 class="h5 mb-1" id="presence-products-label">{{ __('sales_chat_presence.products') }}</h2>
                    <ul class="presence-list" data-presence-products></ul>
                    <p class="text-muted mb-0" data-presence-products-empty>{{ __('sales_chat_presence.no_products') }}</p>
                </div>
            </section>
            <section class="card mb-0" aria-labelledby="presence-sections-label">
                <div class="card-body">
                    <h2 class="h5 mb-1" id="presence-sections-label">{{ __('sales_chat_presence.sections_title') }}</h2>
                    <ul class="presence-list" data-presence-sections></ul>
                    <p class="text-muted mb-0" data-presence-sections-empty>{{ __('sales_chat_presence.no_sections') }}</p>
                </div>
            </section>
        </div>

        <section class="card mb-0" aria-labelledby="presence-visitors-label">
            <div class="card-body">
                <div class="presence-header mb-1">
                    <h2 class="h5 mb-0" id="presence-visitors-label">{{ __('sales_chat_presence.visitors') }}</h2>
                    <p class="small text-muted mb-0" data-presence-limit hidden></p>
                </div>
                <ul class="presence-list" data-presence-visitors></ul>
                <p class="text-muted mb-0" data-presence-visitors-empty>{{ __('sales_chat_presence.no_visitors') }}</p>
            </div>
        </section>
    </div>

    @push('styles')
        <style>
            .sales-presence { max-width: 1240px; margin-inline: auto }
            .presence-header, .presence-summary__body {
                display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
            }
            .presence-summary { border-inline-start: 4px solid #ff6900 }
            .presence-summary__body { padding-block: 1.3rem }
            .presence-summary__side { text-align: end }
            .presence-total { display: block; line-height: 1; font-size: clamp(2.3rem, 7vw, 3.3rem); font-variant-numeric: tabular-nums }
            .presence-status { font-weight: 600 }
            .presence-status::before {
                display: inline-block; width: .63rem; height: .63rem; margin-inline-end: .45rem; border-radius: 50%;
                background: #28c76f; content: "";
            }
            .presence-status[data-stale]::before { background: #ff9f43 }
            .presence-columns { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr); gap: 1rem }
            .presence-list { list-style: none; padding: 0; margin: 0 }
            .presence-list li { display: flex; align-items: center; justify-content: space-between; gap: .6rem; min-height: 2.7rem; padding-block: .54rem }
            .presence-list li + li { border-top: 1px solid var(--bs-border-color, rgba(128, 128, 128, .28)) }
            .presence-list a { text-decoration: underline; text-underline-offset: .15em; overflow-wrap: anywhere }
            .presence-count { flex-shrink: 0; min-width: 2.25rem; text-align: center; font-variant-numeric: tabular-nums }
            .presence-visitor { display: grid; gap: .16rem; min-width: 0 }
            .presence-visitor code { color: inherit; font-weight: 600 }
            .presence-visitor__time { flex-shrink: 0; font-size: .8rem; font-variant-numeric: tabular-nums }
            .sales-presence [hidden] { display: none !important }
            @media (max-width: 767px) {
                .presence-columns { grid-template-columns: minmax(0, 1fr) }
                .presence-summary__side { text-align: start }
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            (() => {
                const board = document.getElementById('sales-presence');
                const read = (part) => board.querySelector('[data-presence-' + part + ']');
                const copy = @js(__('sales_chat_presence'));
                const locale = document.documentElement.lang || 'ka';
                const counts = new Intl.NumberFormat(locale);
                const clock = new Intl.DateTimeFormat(locale, {
                    timeZone: 'Asia/Tbilisi', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
                });
                const signatures = new Map();
                let refreshing = false;

                function safeProductUrl(value) {
                    if (typeof value !== 'string' || !value) return null;
                    try {
                        const url = new URL(value, window.location.origin);
                        if ((url.protocol === 'http:' || url.protocol === 'https:')
                            && url.origin === window.location.origin && !url.username && !url.password
                            && /^\/(?:[a-z]{2}(?:-[a-z]{2})?\/)?product\/[^/]+$/i.test(url.pathname)) {
                            return url.origin + url.pathname;
                        }
                    } catch {
                        // The product remains visible as plain text if its URL cannot be used.
                    }
                    return null;
                }

                function productLabel(name, href) {
                    const safe = safeProductUrl(href);
                    const label = document.createElement(safe ? 'a' : 'span');
                    label.textContent = name;
                    if (safe) {
                        label.href = safe;
                        label.target = '_blank';
                        label.rel = 'noopener';
                    }
                    return label;
                }

                function sectionLabel(key) {
                    return Object.prototype.hasOwnProperty.call(copy.sections, key) ? copy.sections[key] : copy.sections.other;
                }

                function countRow(label, value) {
                    const row = document.createElement('li');
                    const count = document.createElement('span');
                    count.className = 'badge bg-light-primary presence-count';
                    count.textContent = counts.format(value);
                    row.append(label, count);
                    return row;
                }

                function visitorRow(visitor) {
                    const row = document.createElement('li');
                    const info = document.createElement('div');
                    info.className = 'presence-visitor';
                    const code = document.createElement('code');
                    code.textContent = visitor.code;
                    const where = visitor.product_name
                        ? productLabel(visitor.product_name, visitor.product_url)
                        : document.createTextNode(sectionLabel(visitor.section_key));
                    info.append(code, where);
                    const time = document.createElement('time');
                    time.className = 'presence-visitor__time text-muted';
                    const activeAt = Date.parse(visitor.active_at);
                    if (Number.isFinite(activeAt)) {
                        time.dateTime = new Date(activeAt).toISOString();
                        time.textContent = clock.format(activeAt);
                    }
                    row.append(info, time);
                    return row;
                }

                function renderList(key, rows, makeRow) {
                    const signature = JSON.stringify(rows);
                    if (signature === signatures.get(key)) return;
                    const list = read(key);
                    const focus = list.contains(document.activeElement) ? document.activeElement.getAttribute('href') : null;
                    signatures.set(key, signature);
                    list.replaceChildren(...rows.map(makeRow));
                    read(key + '-empty').hidden = rows.length !== 0;
                    if (focus) {
                        [...list.querySelectorAll('a')].find(link => link.getAttribute('href') === focus)?.focus({preventScroll: true});
                    }
                }

                function render(data) {
                    const total = counts.format(data.active_count);
                    if (read('total').textContent !== total) read('total').textContent = total;
                    read('window').textContent = copy.window.replace(':seconds', counts.format(data.window_seconds));
                    renderList('products', data.products, product => countRow(productLabel(product.name, product.url), product.active_count));
                    const otherSections = data.sections.filter(section => section.key !== 'product');
                    renderList('sections', otherSections, section => countRow(document.createTextNode(sectionLabel(section.key)), section.active_count));
                    const visitors = data.visitors.filter(visitor => typeof visitor.code === 'string' && /^[A-F0-9]{8}$/.test(visitor.code));
                    renderList('visitors', visitors, visitorRow);
                    const limit = read('limit');
                    limit.hidden = visitors.length === 0 || visitors.length >= data.active_count;
                    limit.textContent = copy.showing.replace(':count', counts.format(visitors.length));
                    read('updated').textContent = copy.updated.replace(':time', clock.format(Date.now()));
                    read('status').removeAttribute('data-stale');
                    if (read('status').textContent !== copy.live) read('status').textContent = copy.live;
                }

                async function refresh() {
                    if (refreshing || document.hidden) return;
                    refreshing = true;
                    const control = new AbortController();
                    const timeout = window.setTimeout(() => control.abort(), 8000);
                    try {
                        const response = await fetch(board.dataset.url, {
                            credentials: 'same-origin', headers: {Accept: 'application/json'}, cache: 'no-store', signal: control.signal,
                        });
                        if (!response.ok) throw new Error('Presence unavailable');
                        const data = await response.json();
                        if (!Number.isInteger(data.active_count) || !Number.isInteger(data.window_seconds) || !Array.isArray(data.products)
                            || !Array.isArray(data.sections) || !Array.isArray(data.visitors)) {
                            throw new Error('Invalid presence data');
                        }
                        render(data);
                    } catch {
                        const status = read('status');
                        status.setAttribute('data-stale', '');
                        if (status.textContent !== copy.stale) status.textContent = copy.stale;
                    } finally {
                        window.clearTimeout(timeout);
                        refreshing = false;
                    }
                }

                render(@js($presence));
                window.setInterval(refresh, 6000);
                document.addEventListener('visibilitychange', () => { if (!document.hidden) void refresh(); });
            })();
        </script>
    @endpush
@endcomponent
