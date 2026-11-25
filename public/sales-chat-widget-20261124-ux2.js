(() => {
    'use strict';

    const pollDelay = 4000;

    function localCatalogUrl(value) {
        if (typeof value !== 'string') {
            return null;
        }

        try {
            const url = new URL(value, window.location.origin);
            const catalogPath = /^\/(?:[a-z]{2}(?:-[a-z]{2})?\/)?(?:product|catalog)(?:\/|$)/i;
            return (url.protocol === 'https:' || url.protocol === 'http:')
                && url.origin === window.location.origin
                && !url.username
                && !url.password
                && catalogPath.test(url.pathname) ? url.href : null;
        } catch {
            return null;
        }
    }

    function localOrderUrl(value, productUrl, id, installment) {
        const safe = localCatalogUrl(value);
        if (!safe) {
            return null;
        }

        const actionUrl = new URL(safe);
        const expectedQuery = installment ? '?sales_chat_payment=installment' : '';
        return actionUrl.pathname === productUrl.pathname
            && actionUrl.hash === '#quick-order-' + id
            && actionUrl.search === expectedQuery
            ? actionUrl.href : null;
    }

    function clientMessageId() {
        if (typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        const bytes = window.crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = [...bytes].map(byte => byte.toString(16).padStart(2, '0')).join('');
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }

    function initChat(widget) {
        const copy = JSON.parse(widget.dataset.copy);
        const panel = widget.querySelector('[data-chat-panel]');
        const launcher = widget.querySelector('[data-chat-toggle]');
        const closeButton = widget.querySelector('[data-chat-close]');
        const modeLabel = widget.querySelector('[data-chat-mode]');
        const log = widget.querySelector('[data-chat-messages]');
        const notice = widget.querySelector('[data-chat-notice]');
        const typing = document.createElement('p');
        typing.className = 'iapi-sales-chat__typing';
        typing.setAttribute('role', 'status');
        typing.setAttribute('aria-atomic', 'true');
        typing.hidden = true;
        const retryButton = document.createElement('button');
        retryButton.type = 'button';
        retryButton.className = 'iapi-sales-chat__choose';
        retryButton.textContent = copy.retry_message;
        retryButton.hidden = true;
        notice.after(typing, retryButton);
        const composer = widget.querySelector('[data-chat-form]');
        const input = composer.elements.namedItem('message');
        const sendButton = widget.querySelector('[data-chat-send]');
        const contact = widget.querySelector('[data-chat-lead]');
        const productSelect = contact.elements.namedItem('product');
        const fullName = contact.elements.namedItem('name');
        const phone = contact.elements.namedItem('phone');
        const saveButton = widget.querySelector('[data-chat-save]');
        const products = new Map();
        const messageIds = new Set();
        const roles = ['visitor', 'assistant', 'operator', 'system'];
        const productSlug = widget.dataset.productSlug;

        let ready = false;
        let sending = false;
        let leadSaved = false;
        let showContact = false;
        let chatMode = 'assistant';
        let pollInFlight = false;
        let timer;
        let pollController;
        let requestNumber = 0;
        let pendingMessage;
        let optimisticMessage;
        let installmentAction;

        function setNotice(message) {
            notice.textContent = message || '';
            notice.hidden = !message;
        }

        function updateControls() {
            sendButton.disabled = !ready || sending;
            retryButton.disabled = !ready || sending;
            saveButton.disabled = !ready || sending || leadSaved;
            contact.hidden = leadSaved || !showContact;
            modeLabel.textContent = ready ? copy[chatMode] : copy.loading;
            if (installmentAction) {
                installmentAction.disabled = !ready || sending;
                installmentAction.hidden = chatMode !== 'assistant' || messageIds.size > 1;
            }
        }

        function makeBubble(role, body) {
            const message = document.createElement('article');
            message.className = 'iapi-sales-chat__message iapi-sales-chat__message--' + role;
            const author = document.createElement('p');
            author.className = 'iapi-sales-chat__author';
            author.textContent = copy[role];
            const content = document.createElement('p');
            content.className = 'iapi-sales-chat__body';
            content.textContent = body;
            message.append(author, content);
            return message;
        }

        function chooseProduct(id) {
            if (leadSaved || !products.has(id)) {
                return;
            }

            showContact = true;
            productSelect.value = String(id);
            updateControls();
            phone.focus();
        }

        function makeOrderAction(url, label, primary) {
            const action = document.createElement('a');
            action.className = 'iapi-sales-chat__action' + (primary ? ' iapi-sales-chat__action--primary' : '');
            action.href = url;
            action.textContent = label;
            action.addEventListener('click', event => {
                if (!event.ctrlKey && !event.shiftKey && !event.metaKey && !event.altKey) {
                    setOpen(false);
                }
            });
            return action;
        }

        function makeCard(card) {
            if (!card || typeof card.name !== 'string' || !card.name.trim()) {
                return null;
            }

            const url = localCatalogUrl(card.url);
            if (!url) {
                return null;
            }

            const item = document.createElement('li');
            item.className = 'iapi-sales-chat__card';
            const link = document.createElement('a');
            link.href = url;
            link.textContent = card.name;
            item.append(link);

            if (typeof card.price_text === 'string' && card.price_text.trim()) {
                const price = document.createElement('span');
                price.className = 'iapi-sales-chat__price';
                price.textContent = card.price_text;
                item.append(price);
            }

            const productUrl = new URL(url);
            const isProduct = /^\/(?:[a-z]{2}(?:-[a-z]{2})?\/)?product\/[^/]+$/i.test(productUrl.pathname);
            const id = Number(card.id);
            if (isProduct && Number.isSafeInteger(id) && id > 0) {
                if (!products.has(id)) {
                    products.set(id, card.name);
                    const option = document.createElement('option');
                    option.value = String(id);
                    option.textContent = card.name;
                    productSelect.append(option);
                }

                const actions = document.createElement('div');
                actions.className = 'iapi-sales-chat__card-actions';
                const checkout = localOrderUrl(card.checkout_url, productUrl, id, false);
                const installment = localOrderUrl(card.installment_url, productUrl, id, true);
                if (checkout) {
                    actions.append(makeOrderAction(checkout, copy.checkout, true));
                }
                if (installment) {
                    actions.append(makeOrderAction(installment, copy.online_installment, !checkout));
                }
                const choose = document.createElement('button');
                choose.className = 'iapi-sales-chat__choose';
                choose.type = 'button';
                choose.dataset.chatChoose = String(id);
                choose.textContent = copy.select_card;
                choose.hidden = leadSaved;
                choose.addEventListener('click', () => chooseProduct(id));
                actions.append(choose);
                item.append(actions);
            }

            return item;
        }

        function renderMessages(rows) {
            if (!Array.isArray(rows)) {
                return;
            }

            const atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 32;
            for (const row of rows) {
                if (!row || (typeof row.id !== 'number' && typeof row.id !== 'string')
                    || typeof row.body !== 'string') {
                    continue;
                }

                const id = String(row.id);
                if (messageIds.has(id)) {
                    continue;
                }

                log.querySelector('[data-chat-empty]')?.remove();
                const role = roles.includes(row.role) ? row.role : 'system';
                const message = makeBubble(role, row.body);
                if (id === '0') {
                    installmentAction = document.createElement('button');
                    installmentAction.className = 'iapi-sales-chat__choose iapi-sales-chat__prompt';
                    installmentAction.type = 'button';
                    installmentAction.textContent = copy.installments;
                    installmentAction.addEventListener('click', () => { void sendMessage(copy.installment_question); });
                    message.append(installmentAction);
                }
                if (Array.isArray(row.cards) && row.cards.length) {
                    const cards = document.createElement('ul');
                    cards.className = 'iapi-sales-chat__cards';
                    row.cards.forEach(card => {
                        const item = makeCard(card);
                        if (item) {
                            cards.append(item);
                        }
                    });
                    if (cards.childElementCount) {
                        message.append(cards);
                    }
                }

                messageIds.add(id);
                log.append(message);
            }

            if (!log.childElementCount) {
                const fallback = makeBubble('assistant', copy.empty);
                fallback.dataset.chatEmpty = '';
                log.append(fallback);
            }

            if (atBottom || sending) {
                log.scrollTop = log.scrollHeight;
            }
        }

        function render(state) {
            ready = true;
            if (['assistant', 'waiting', 'operator'].includes(state.mode)) {
                chatMode = state.mode;
            }
            if (state.lead_saved === true && !leadSaved) {
                leadSaved = true;
                phone.value = '';
                fullName.value = '';
                setNotice(copy.saved);
                log.querySelectorAll('[data-chat-choose]').forEach(button => { button.hidden = true; });
            }
            renderMessages(state.messages);
            updateControls();
        }

        async function request(key, payload, controller = new AbortController()) {
            const number = ++requestNumber;
            const options = {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': widget.dataset.csrf },
                signal: controller.signal,
                cache: 'no-store',
            };

            if (payload !== undefined) {
                options.method = 'POST';
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(payload);
            }

            const deadline = window.setTimeout(() => controller.abort(), 30000);
            try {
                const response = await fetch(widget.dataset[key + 'Url'], options);
                if (!response.ok) {
                    const error = new Error('Chat request failed');
                    error.status = response.status;
                    if (error.status === 422) {
                        const result = await response.json().catch(() => null);
                        error.fields = Object.keys(result?.errors ?? {});
                    }
                    throw error;
                }

                const state = await response.json();
                if (!state || !Array.isArray(state.messages)) {
                    throw new Error('Invalid chat response');
                }

                if (number === requestNumber) {
                    if (key === 'message') {
                        optimisticMessage?.remove();
                    }
                    render(state);
                }
                return true;
            } finally {
                window.clearTimeout(deadline);
            }
        }

        function statusFor(error) {
            if (error.status === 429) {
                return copy.rate_limited;
            }
            if (error.status === 422 || error.status === 409) {
                if (error.fields?.includes('phone')) {
                    return copy.phone_error;
                }
                if (error.fields?.includes('product_id')) {
                    return copy.product_error;
                }
                return copy.validation_error;
            }
            return copy.network_error;
        }

        async function poll() {
            if (panel.hidden || document.hidden || sending || pollInFlight) {
                return;
            }
            const wasReady = ready;
            const controller = new AbortController();
            pollController = controller;
            pollInFlight = true;
            try {
                await request('show', undefined, controller);
                if (!wasReady && !leadSaved) {
                    setNotice('');
                }
            } catch (error) {
                if (!ready && !panel.hidden) {
                    setNotice(statusFor(error));
                }
            } finally {
                pollInFlight = false;
                if (pollController === controller) {
                    pollController = undefined;
                }
            }
        }

        async function post(key, payload) {
            if (!ready || sending) {
                return false;
            }
            pollController?.abort();
            sending = true;
            setNotice('');
            updateControls();
            try {
                return await request(key, payload);
            } catch (error) {
                setNotice(statusFor(error));
                return false;
            } finally {
                sending = false;
                updateControls();
            }
        }

        function setOpen(open) {
            panel.hidden = !open;
            launcher.setAttribute('aria-expanded', String(open));
            window.clearInterval(timer);
            if (open) {
                panel.focus();
                void poll();
                timer = window.setInterval(poll, pollDelay);
            } else {
                pollController?.abort();
                launcher.focus();
            }
        }

        launcher.addEventListener('click', () => setOpen(panel.hidden));
        closeButton.addEventListener('click', () => setOpen(false));
        panel.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                setOpen(false);
            }
        });
        document.addEventListener('visibilitychange', () => { if (!document.hidden) void poll(); });

        async function sendMessage(message) {
            if (!message || !ready || sending) {
                return;
            }
            if (pendingMessage?.message !== message) {
                pendingMessage = { message, client_message_id: clientMessageId() };
                if (productSlug) {
                    pendingMessage.product_slug = productSlug;
                }
            }
            const submittedDraft = input.value.trim() === message ? input.value : null;
            if (submittedDraft !== null) {
                input.value = '';
            }
            if (!panel.hidden && composer.contains(document.activeElement)) {
                input.focus();
            }

            retryButton.hidden = true;
            optimisticMessage = makeBubble('visitor', message);
            log.append(optimisticMessage);
            log.scrollTop = log.scrollHeight;
            typing.hidden = false;
            typing.textContent = chatMode === 'assistant' ? copy.typing : copy.sending;

            const posted = await post('message', pendingMessage);
            optimisticMessage.remove();
            optimisticMessage = undefined;
            typing.textContent = '';
            typing.hidden = true;
            if (posted) {
                pendingMessage = undefined;
            } else {
                if (submittedDraft !== null && input.value === '') {
                    input.value = submittedDraft;
                }
                retryButton.hidden = false;
            }
        }

        composer.addEventListener('submit', event => {
            event.preventDefault();
            void sendMessage(input.value.trim());
        });

        input.addEventListener('keydown', event => {
            if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.keyCode === 229) {
                return;
            }
            event.preventDefault();
            if (!event.repeat) {
                void sendMessage(input.value.trim());
            }
        });

        retryButton.addEventListener('click', () => {
            if (pendingMessage) {
                input.focus();
                void sendMessage(pendingMessage.message);
            }
        });

        contact.addEventListener('submit', async event => {
            event.preventDefault();
            const id = Number(productSelect.value);
            if (!products.has(id) || !phone.value.trim()) {
                return;
            }
            const lead = { phone: phone.value.trim(), product_id: id, consent: true };
            const name = fullName.value.trim();
            if (name) {
                lead.name = name;
            }
            if (await post('lead', lead)) {
                input.focus();
            }
        });

        updateControls();
    }

    document.querySelectorAll('[data-sales-chat]').forEach(initChat);
})();
