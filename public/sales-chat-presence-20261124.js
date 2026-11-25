(() => {
    'use strict';

    const widget = document.querySelector('[data-sales-chat][data-presence-url]');
    if (!widget) {
        return;
    }

    const endpoint = new URL(widget.dataset.presenceUrl, window.location.href);
    if (endpoint.origin !== window.location.origin) {
        return;
    }

    const payload = {section: widget.dataset.presenceSection || 'other'};
    if (payload.section === 'product' && widget.dataset.productSlug) {
        payload.product_slug = widget.dataset.productSlug;
    }

    let inFlight = false;
    let lastSentAt = 0;

    async function heartbeat() {
        if (document.visibilityState !== 'visible' || inFlight || Date.now() - lastSentAt < 5000) {
            return;
        }

        inFlight = true;
        lastSentAt = Date.now();
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 8000);
        try {
            await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': widget.dataset.csrf,
                },
                body: JSON.stringify(payload),
                signal: controller.signal,
            });
        } catch {
            // The next scheduled heartbeat can recover without interrupting the shop.
        } finally {
            window.clearTimeout(timeout);
            inFlight = false;
        }
    }

    document.addEventListener('visibilitychange', heartbeat);
    window.addEventListener('pageshow', heartbeat);
    window.setInterval(heartbeat, 25000);
    void heartbeat();
})();
