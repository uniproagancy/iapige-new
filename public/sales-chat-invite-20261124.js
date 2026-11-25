(() => {
    'use strict';

    const widget = document.querySelector('[data-sales-chat][data-chat-invite-text]');
    if (!widget || widget.dataset.chatInviteInitialized === 'true') return;

    const launcher = widget.querySelector('[data-chat-toggle]');
    const panel = widget.querySelector('[data-chat-panel]');
    const {chatInviteText, chatInviteOpen, chatInviteClose} = widget.dataset;
    if (!launcher || !panel || !chatInviteText || !chatInviteOpen || !chatInviteClose) return;
    widget.dataset.chatInviteInitialized = 'true';

    const storageKey = 'iapi.sales-chat.invitation.v1';
    const delay = 20_000;
    let saved = null;
    try {
        saved = window.sessionStorage.getItem(storageKey);
    } catch {
        // An unavailable session store still allows one invitation on this page.
    }
    if (saved === 'seen') return;

    let remaining = saved !== null && /^\d+$/.test(saved) ? Math.min(delay, Number(saved)) : delay;
    let startedAt = null;
    let timer = null;
    let notice = null;
    let done = false;

    function remember(value) {
        try {
            window.sessionStorage.setItem(storageKey, value);
        } catch {
            // The page keeps the current invitation state in memory.
        }
    }

    function pause() {
        if (timer !== null) window.clearTimeout(timer);
        timer = null;
        if (startedAt !== null) {
            remaining = Math.max(0, remaining - (performance.now() - startedAt));
            startedAt = null;
            remember(String(Math.ceil(remaining)));
        }
    }

    function finish() {
        done = true;
        pause();
        remember('seen');
        notice?.remove();
        document.removeEventListener('visibilitychange', syncVisibility);
        window.removeEventListener('pageshow', syncVisibility);
        window.removeEventListener('pagehide', pause);
        launcher.removeEventListener('click', finish, true);
    }

    function reveal() {
        if (done || !widget.isConnected || document.visibilityState !== 'visible') return;
        if (!panel.hidden) {
            finish();
            return;
        }
        remember('seen');
        notice = document.createElement('div');
        notice.className = 'iapi-sales-chat__invite';

        const open = document.createElement('button');
        open.type = 'button';
        open.className = 'iapi-sales-chat__invite-open';
        if (panel.id) open.setAttribute('aria-controls', panel.id);

        const text = document.createElement('span');
        text.className = 'iapi-sales-chat__invite-text';
        text.textContent = chatInviteText;
        const label = document.createElement('span');
        label.className = 'iapi-sales-chat__invite-label';
        label.textContent = chatInviteOpen;
        open.append(text, label);
        open.addEventListener('click', () => {
            finish();
            if (panel.hidden) launcher.click();
        });

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'iapi-sales-chat__invite-close';
        close.setAttribute('aria-label', chatInviteClose);
        close.textContent = '×';
        close.addEventListener('click', () => {
            const returnFocus = notice.contains(document.activeElement);
            finish();
            if (returnFocus) launcher.focus();
        });

        notice.append(open, close);
        launcher.after(notice);
    }

    function syncVisibility() {
        if (document.visibilityState !== 'visible') {
            pause();
        } else if (!done && notice === null && timer === null) {
            startedAt = performance.now();
            timer = window.setTimeout(() => {
                pause();
                if (remaining > 0) {
                    syncVisibility();
                } else {
                    reveal();
                }
            }, remaining);
        }
    }

    if (!panel.hidden) {
        finish();
        return;
    }
    launcher.addEventListener('click', finish, true);
    document.addEventListener('visibilitychange', syncVisibility);
    window.addEventListener('pageshow', syncVisibility);
    window.addEventListener('pagehide', pause);
    syncVisibility();
})();
