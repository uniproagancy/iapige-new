@once
    @push('scripts')
        <script>
            (() => {
                if (window.IAPIQuickOrderUI) { window.IAPIQuickOrderUI.refresh(); return; }
                const state = { bar: null, form: null, header: null, frame: null, body: null };
                const resize = typeof ResizeObserver === 'function' ? new ResizeObserver(schedule) : null;
                const mutations = typeof MutationObserver === 'function' ? new MutationObserver(schedule) : null;
                function visibility(show) {
                    if (!state.bar) return;
                    state.bar.hidden = !show;
                    state.bar.inert = !show;
                    state.bar.setAttribute('aria-hidden', show ? 'false' : 'true');
                    if (show) state.bar.removeAttribute('inert');
                    else state.bar.setAttribute('inert', '');
                }
                function targets() {
                    const bar = document.querySelector('[data-quick-order-bar]');
                    const form = bar ? document.getElementById(bar.getAttribute('data-quick-order-bar')) : null;
                    const header = document.getElementById('header');
                    if (bar !== state.bar || form !== state.form || header !== state.header || document.body !== state.body) {
                        visibility(false);
                        resize?.disconnect(); mutations?.disconnect();
                        Object.assign(state, { bar, form, header, body: document.body });
                        [form, header, document.body].filter(Boolean).forEach(el => resize?.observe(el));
                        if (document.body) mutations?.observe(document.body, { childList: true, subtree: true });
                    }
                }
                function measure() {
                    state.frame = null;
                    targets();
                    if (!state.bar || !state.form) { visibility(false); return; }
                    const bounds = state.form.getBoundingClientRect();
                    const headerBottom = Math.max(0, state.header?.getBoundingClientRect().bottom ?? 0);
                    visibility(window.matchMedia('(max-width: 768px)').matches && bounds.height > 0 && bounds.bottom <= headerBottom);
                }
                function schedule() {
                    if (state.frame === null) state.frame = requestAnimationFrame(measure);
                }
                window.IAPIQuickOrderUI = { refresh: schedule };
                window.addEventListener('scroll', schedule, { passive: true });
                window.addEventListener('resize', schedule, { passive: true });
                document.addEventListener('livewire:navigated', schedule);
                document.addEventListener('click', event => {
                    const opener = event.target.closest('[data-quick-order-open]');
                    if (!opener) return;
                    const form = document.getElementById(opener.getAttribute('data-quick-order-open'));
                    if (!form) return;
                    event.preventDefault();
                    form.querySelector('[data-quick-order-name], [data-quick-order-again]')?.focus({ preventScroll: true });
                    targets(); visibility(false);
                    const offset = Math.max(0, document.getElementById('header')?.getBoundingClientRect().bottom ?? 0) + 16;
                    window.scrollTo({ top: Math.max(0, form.getBoundingClientRect().top + window.scrollY - offset), behavior: 'auto' });
                    schedule();
                });
                schedule();
            })();
        </script>
    @endpush
@endonce
