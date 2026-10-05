/**
 * IAPI.GE — behaviour shared by every page.
 * Page-specific code lives in resources/js/pages/*.js.
 */
import {
    $, $$, toast, isNarrow,
    openPanel, closePanels, currentPanel,
    railStep, syncRail, selectCategory, storage, t,
} from './core.js';
import { confirm } from './ui.js';

/* ------------------------------------------------------------------ sticky header */

/* compact state + shadow once the page scrolls past the header */
let headerObserver = null;

function bindStickyHeader() {
    const sentinel = document.getElementById('headSentinel');
    const header = document.getElementById('header');

    headerObserver?.disconnect();

    if (!sentinel || !header || !('IntersectionObserver' in window)) return;

    headerObserver = new IntersectionObserver(([entry]) => {
        header.classList.toggle('is-stuck', !entry.isIntersecting);
    });
    headerObserver.observe(sentinel);
}

/* ------------------------------------------------------------------ rails */

function bindRails() {
    $$('.rail, .brands__rail').forEach((rail) => {
        if (rail.dataset.railBound) return;
        rail.dataset.railBound = '1';
        rail.addEventListener('scroll', () => syncRail(rail), { passive: true });
        syncRail(rail);
    });
}

window.addEventListener('resize', () => $$('.rail, .brands__rail').forEach(syncRail));

/* ------------------------------------------------------------------ search tab */

/* the mobile "search" tab focuses the Livewire search box, or jumps home for it */
function focusSearch() {
    const input = document.getElementById('searchInput');

    if (input && input.offsetParent !== null) {
        window.scrollTo({ top: 0, behavior: 'smooth' });
        setTimeout(() => input.focus(), 260);
        return;
    }

    window.location.href = `${window.IAPI?.homeUrl ?? '/'}#search`;
}

if (location.hash === '#search') {
    setTimeout(() => document.getElementById('searchInput')?.focus(), 400);
}

/* ------------------------------------------------------------------ product cards */

function setCardImage(card, index) {
    const images = JSON.parse(card.dataset.images || '[]');
    if (!images.length) return;

    const i = (index + images.length) % images.length;
    card.dataset.index = i;

    const img = card.querySelector('[data-img]');
    if (img) {
        img.classList.remove('is-broken');
        img.src = images[i];
    }

    const fallback = card.querySelector('.card__fallback');
    if (fallback) fallback.textContent = `PHOTO ${i + 1}/${images.length}`;

    card.querySelectorAll('.card__dot').forEach((dot, di) => dot.classList.toggle('is-active', di === i));
}

/** brief "added" state on the card's cart button */
function flashAdded(btn) {
    btn.classList.add('is-added');
    btn.querySelector('use')?.setAttribute('href', '#i-check');
    clearTimeout(btn._t);
    btn._t = setTimeout(() => {
        btn.classList.remove('is-added');
        btn.querySelector('use')?.setAttribute('href', '#i-shopping-bag');
    }, 1700);
}

/* ------------------------------------------------------------------ auth modal */

function setAuthMode(mode) {
    $$('.auth__tab').forEach((tab) => {
        const on = tab.dataset.mode === mode;
        tab.classList.toggle('is-active', on);
        tab.setAttribute('aria-selected', on);
    });
    $$('[data-auth-panel]').forEach((p) => { p.hidden = p.dataset.authPanel !== mode; });
    $$('[data-auth-aside]').forEach((p) => { p.hidden = p.dataset.authAside !== mode; });
}

/* ------------------------------------------------------------------ cookie bar */

const COOKIE_KEY = 'iapi_cookie_consent';

/*
 * The answer is mirrored into a cookie as well as localStorage: the server
 * decides whether to send Conversions API events, and it can only read cookies.
 */
function rememberConsent(choice) {
    storage.set(COOKIE_KEY, choice);
    document.cookie = `cookie_consent=${choice}; path=/; max-age=31536000; samesite=lax`;

    if (choice === 'all') window.dispatchEvent(new Event('consent:all'));
}

function showCookieBar() {
    const bar = document.getElementById('cookies');
    const choice = storage.get(COOKIE_KEY, null);

    // answered on an earlier visit, before the cookie existed
    if (choice && !document.cookie.split('; ').includes(`cookie_consent=${choice}`)) {
        rememberConsent(choice);
    }

    if (!bar) return;

    if (!choice) {
        bar.hidden = false;
        document.body.classList.add('has-cookies');
    }
}

/* ------------------------------------------------------------------ one click handler */

document.addEventListener('click', (e) => {
    const el = e.target;
	
	const head = el.closest('[data-fgroup]');
    if (head) {
        const group = head.closest('.fgroup');
        const open = group.classList.toggle('is-open');
        head.setAttribute('aria-expanded', open);
        return;
    }

    /* ---- confirm before an irreversible action (SweetAlert) ---- */
    const guarded = el.closest('[data-confirm]');
    if (guarded) {
        e.preventDefault();
        e.stopPropagation();

        const root = guarded.closest('[wire\\:id]');
        const action = guarded.dataset.confirmAction;

        confirm(guarded.dataset.confirm, { title: guarded.dataset.confirmTitle }).then((ok) => {
            if (ok && root && action) {
                window.Livewire.find(root.getAttribute('wire:id')).call(action);
            }
        });
        return;
    }

    /* ---- header buttons (delegated, so a DOM swap never breaks them) ---- */

    if (el.closest('#catalogBtn')) {
        currentPanel() === 'catalog' ? closePanels() : openPanel('catalog');
        return;
    }
    if (el.closest('#cartBtn')) { openPanel('cart'); return; }
    if (el.closest('#authBtn')) { openPanel('auth'); return; }
    if (el.closest('#scrim')) { closePanels(); return; }
    if (el.closest('[data-search-tab]')) { focusSearch(); return; }

    /* ---- panels ---- */

    if (el.closest('[data-close]')) { closePanels(); return; }

    const opener = el.closest('[data-open]');
    if (opener && !el.closest('a[href]')) {
        e.preventDefault();
        openPanel(opener.dataset.open);
        return;
    }

    /* ---- add to cart — the CartDrawer component listens for this event ---- */

    const add = el.closest('[data-add]');
    if (add) {
        const card = add.closest('[data-product-id]');
        if (card) {
            window.Livewire?.dispatch('cart-add', { productId: card.dataset.productId, qty: 1 });
            toast(t('cartAdded', { name: card.dataset.productName ?? '' }));
            flashAdded(add);
        }
        return;
    }

    /* ---- product card image carousel ---- */

    const card = el.closest('[data-card]');
    if (card) {
        const index = Number(card.dataset.index || 0);
        if (el.closest('[data-img-prev]')) { setCardImage(card, index - 1); return; }
        if (el.closest('[data-img-next]')) { setCardImage(card, index + 1); return; }

        const dot = el.closest('[data-img-go]');
        if (dot) { setCardImage(card, Number(dot.dataset.imgGo)); return; }
    }

    /* ---- rails ---- */

    const railBtn = el.closest('[data-rail]');
    if (railBtn) {
        const rail = document.getElementById(railBtn.dataset.rail);
        rail?.scrollBy({ left: railStep(rail) * Number(railBtn.dataset.dir), behavior: 'smooth' });
        return;
    }

    /* ---- catalog drawer ---- */

    const cat = el.closest('[data-cat]');
    if (cat) { selectCategory(Number(cat.dataset.cat), true); return; }

    /* ---- auth modal ---- */

    const mode = el.closest('[data-mode]');
    if (mode) { setAuthMode(mode.dataset.mode); return; }

    const reveal = el.closest('[data-reveal]');
    if (reveal) {
        const input = reveal.parentElement.querySelector('input');
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        reveal.textContent = show ? t('hide') : t('show');
        return;
    }

    /* ---- cookie consent ---- */

    const consent = el.closest('[data-cookies]');
    if (consent) {
        rememberConsent(consent.dataset.cookies);
        const bar = document.getElementById('cookies');
        if (bar) bar.hidden = true;
        document.body.classList.remove('has-cookies');
        toast(t(consent.dataset.cookies === 'all' ? 'cookiesAll' : 'cookiesNeed'));
    }
});

/* desktop: hovering a category previews its sub-categories */
document.addEventListener('mouseover', (e) => {
    if (isNarrow()) return;
    if (!e.target.closest('#catsList')) return;

    const cat = e.target.closest('[data-cat]');
    if (cat) selectCategory(Number(cat.dataset.cat));
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && currentPanel()) closePanels();
});

/* broken remote images fall back to the grey "PHOTO" placeholder */
document.addEventListener('error', (e) => {
    if (e.target.tagName === 'IMG') e.target.classList.add('is-broken');
}, true);

/* ------------------------------------------------------------------ Livewire bridge */

document.addEventListener('livewire:init', () => {
    /* the cart drawer reports its new state; the header badge follows */
    window.Livewire.on('cart-updated', ({ count, total }) => {
        const badge = document.getElementById('cartBadge');
        const tab = document.getElementById('tabBadge');
        const totalEl = document.getElementById('cartTotalTop');

        if (badge) {
            badge.textContent = count;
            badge.classList.remove('is-bumping');
            void badge.offsetWidth;
            badge.classList.add('is-bumping');
        }
        if (tab) { tab.textContent = count; tab.hidden = !count; }
        if (totalEl && total !== undefined) totalEl.textContent = total;
    });

    /* any component can ask for a notification: $this->dispatch('toast', message: '…') */
    window.Livewire.on('toast', ({ message, type }) => toast(message, type ?? 'success'));

    /* a guest tried to use the wishlist */
    window.Livewire.on('open-auth', () => openPanel('auth'));
});

/* Livewire replaced the page — rebind what points at concrete elements */
document.addEventListener('livewire:navigated', () => {
    bindStickyHeader();
    bindRails();
    showCookieBar();
});

/* ------------------------------------------------------------------ first paint */

bindStickyHeader();
bindRails();
showCookieBar();

/* a message flashed by the server (signed in, signed out, password changed) */
if (window.IAPI?.flash) toast(window.IAPI.flash);