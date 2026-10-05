/**
 * ELIO — shared state and helpers.
 *
 * Imported by app.js and by every page module. ES modules are singletons, so
 * the panel registry exists exactly once per page.
 *
 * The cart and the wishlist are Livewire components (app/Livewire);
 * dialogs and notifications live in ui.js.
 */

/* ------------------------------------------------------------------ helpers */

export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

export const money = (n) => Math.round(n).toLocaleString('ru-RU').replace(/\u00A0/g, ' ') + ' ₾';

export const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[c]));

export const icon = (name, size) =>
    `<svg class="ic${size ? ' ic-' + size : ''}" aria-hidden="true"><use href="#i-${name}"/></svg>`;

export const isNarrow = () => window.matchMedia('(max-width:1024px)').matches;
export const isMobile = () => window.matchMedia('(max-width:768px)').matches;

export const storage = {
    get(key, fallback) {
        try {
            const raw = localStorage.getItem(key);
            return raw === null ? fallback : JSON.parse(raw);
        } catch {
            return fallback;
        }
    },
    set(key, value) {
        try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* storage blocked */ }
    },
};

/* ------------------------------------------------------------------ notifications */

/* Toastify lives in ui.js; re-exported so every importer keeps using core.js */
export { toast } from './ui.js';

/* ------------------------------------------------------------------ panels */

/**
 * name → { selector, hideOnClose() }.
 * hideOnClose lets a panel stay visible after closing (the catalog filter
 * sidebar is a panel on phones but a static column on desktop).
 */
const panels = {
    catalog: { selector: '#catalog' },
    cart:    { selector: '#cart' },
    auth:    { selector: '#authModal' },
};
let current = null;
let lastFocus = null;
const openHooks = [];

export function registerPanel(name, selector, options = {}) {
    panels[name] = { selector, ...options };
}

export function onPanelOpen(fn) { openHooks.push(fn); }

export const currentPanel = () => current;

export function openPanel(name) {
    const panel = panels[name];
    const el = panel && $(panel.selector);
    if (!el) return;

    closePanels(true);
    current = name;
    lastFocus = document.activeElement;

    const scrim = $('#scrim');
    if (scrim) scrim.hidden = false;

    document.body.classList.add('is-locked');
    el.hidden = false;
    if (name === 'catalog') $('#catalogBtn')?.setAttribute('aria-expanded', 'true');

    openHooks.forEach((fn) => fn(name));
    el.querySelector('button, input, a[href]')?.focus({ preventScroll: true });
}

export function closePanels(silent = false) {
    Object.values(panels).forEach((panel) => {
        const el = $(panel.selector);
        if (!el) return;
        if (!panel.hideOnClose || panel.hideOnClose()) el.hidden = true;
    });

    current = null;

    const scrim = $('#scrim');
    if (scrim) scrim.hidden = true;

    document.body.classList.remove('is-locked');
    $('#catalogBtn')?.setAttribute('aria-expanded', 'false');

    if (!silent && lastFocus?.isConnected) lastFocus.focus({ preventScroll: true });
}

/* ------------------------------------------------------------------ i18n */

/** Interface string from window.ELIO.i18n (rendered by the layout in the page language). */
export function t(key, params = {}) {
    let text = window.ELIO?.i18n?.[key] ?? key;
    Object.entries(params).forEach(([name, value]) => { text = text.replaceAll(`:${name}`, value); });
    return text;
}

/* ------------------------------------------------------------------ rails */

export function railStep(rail) {
    const st = getComputedStyle(rail);
    const gap = parseFloat(st.columnGap) || 14;
    return rail.clientWidth - parseFloat(st.paddingLeft) - parseFloat(st.paddingRight) + gap;
}

export function syncRail(rail) {
    const max = rail.scrollWidth - rail.clientWidth - 1;
    $$('[data-rail]').filter((b) => b.dataset.rail === rail.id).forEach((btn) => {
        btn.disabled = Number(btn.dataset.dir) < 0 ? rail.scrollLeft <= 0 : rail.scrollLeft >= max;
    });
}

/* ------------------------------------------------------------------ catalog drawer */

/**
 * Desktop: show the level-2 panel for category `i`.
 * Below 1024px the level-2 panel is hidden, so a click toggles an accordion.
 */
export function selectCategory(i, fromClick = false) {
    const node = $(`[data-cat-node="${i}"]`);
    if (!node) return;

    if (fromClick && isNarrow()) {
        const open = !node.classList.contains('is-open');
        $$('[data-cat-node]').forEach((n) => {
            n.classList.remove('is-open');
            n.querySelector('.cat')?.setAttribute('aria-expanded', 'false');
        });
        node.classList.toggle('is-open', open);
        node.querySelector('.cat')?.setAttribute('aria-expanded', String(open));
    }

    $$('.cat').forEach((c) => c.classList.toggle('is-active', Number(c.dataset.cat) === i));
    $$('[data-l2]').forEach((p) => { p.hidden = Number(p.dataset.l2) !== i; });
}