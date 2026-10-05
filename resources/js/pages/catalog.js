/**
 * Catalog page — filtering, facet counts, sorting and paging over the
 * server-rendered product cards (each card carries its filter values in
 * data-* attributes). This is the piece that becomes a Livewire component
 * once filtering moves to the database.
 */
import { $, $$, money, t, isMobile, registerPanel, currentPanel } from '../core.js';

const root = $('[data-catalog]');
if (root) {
    const grid = $('#catGrid');
    const cards = $$('.card', grid);
    const perPage = Number(root.dataset.perPage) || 8;
    const range = $('#priceRange');
    const priceCeiling = Number(range.max);

    const state = { sub: null, price: priceCeiling, picked: {}, sort: 'popular', page: 1 };

    /* the filter column is a bottom sheet on phones and a static column on desktop */
    registerPanel('filters', '#filters', { hideOnClose: () => isMobile() });
    const syncSheet = () => { $('#filters').hidden = isMobile() && currentPanel() !== 'filters'; };
    window.addEventListener('resize', syncSheet);
    syncSheet();

    const attr = (card, key) => card.dataset[key];
    const picked = (key) => Object.keys(state.picked[key] || {}).filter((v) => state.picked[key][v]);

    /** every filter except `skipKey` — used both for results and for facet counts */
    const matches = (card, skipKey = null) => {
        if (Number(attr(card, 'price')) > state.price) return false;
        if (state.sub && attr(card, 'sub') !== state.sub) return false;
        return ['brand', 'ram', 'screen'].every((key) => {
            if (key === skipKey) return true;
            const on = picked(key);
            return !on.length || on.includes(attr(card, key));
        });
    };

    const sorters = {
        popular:      (a, b) => attr(a, 'order') - attr(b, 'order'),
        'price-asc':  (a, b) => attr(a, 'price') - attr(b, 'price'),
        'price-desc': (a, b) => attr(b, 'price') - attr(a, 'price'),
        new:          (a, b) => attr(b, 'order') - attr(a, 'order'),
    };

    function render() {
        const hits = cards.filter((c) => matches(c)).sort(sorters[state.sort]);
        const visible = hits.slice(0, state.page * perPage);

        /* re-order the DOM so the grid follows the sort */
        hits.forEach((c) => grid.appendChild(c));
        cards.forEach((c) => { c.hidden = !visible.includes(c); });

        $$('[data-shown]').forEach((el) => { el.textContent = hits.length; });
        $('#catEmpty').hidden = hits.length > 0;

        const left = hits.length - visible.length;
        const more = $('#loadMore');
        more.hidden = left <= 0;
        more.textContent = t('more', { count: left });

        const active = Object.values(state.picked).reduce((n, g) => n + Object.values(g).filter(Boolean).length, 0)
            + (state.price < priceCeiling ? 1 : 0) + (state.sub ? 1 : 0);
        const badge = $('#filterBadge');
        badge.textContent = active;
        badge.hidden = active === 0;

        $('#priceMax').textContent = money(state.price);

        /* live facet counts */
        $$('[data-pick]').forEach((opt) => {
            const key = opt.dataset.pick;
            const value = opt.dataset.value;
            const n = cards.filter((c) => attr(c, key) === value && matches(c, key)).length;
            const on = !!state.picked[key]?.[value];
            opt.querySelector('.fopt__count').textContent = n;
            opt.classList.toggle('is-empty', n === 0);
            opt.classList.toggle('is-on', on);
            opt.setAttribute('aria-checked', on);
        });

        $$('[data-chip]').forEach((chip) => {
            const on = chip.dataset.chip === state.sub;
            chip.classList.toggle('is-on', on);
            chip.setAttribute('aria-pressed', on);
        });
    }

    function clearAll() {
        Object.assign(state, { sub: null, price: priceCeiling, picked: {}, page: 1 });
        range.value = priceCeiling;
        render();
    }

    range.addEventListener('input', () => { state.price = Number(range.value); state.page = 1; render(); });
    $('#sort').addEventListener('change', (e) => { state.sort = e.target.value; state.page = 1; render(); });
    $('#loadMore').addEventListener('click', () => { state.page += 1; render(); });

    document.addEventListener('click', (e) => {
        const el = e.target;

        if (el.closest('[data-filter-clear]')) { clearAll(); return; }

        /* sub-category chips (cards carry data-sub too, so chips use data-chip) */
        const chip = el.closest('[data-chip]');
        if (chip) {
            state.sub = state.sub === chip.dataset.chip ? null : chip.dataset.chip;
            state.page = 1;
            render();
            return;
        }

        const opt = el.closest('[data-pick]');
        if (opt) {
            const { pick: key, value } = opt.dataset;
            state.picked[key] = state.picked[key] || {};
            state.picked[key][value] = !state.picked[key][value];
            state.page = 1;
            render();
            return;
        }

        const group = el.closest('[data-fgroup]');
        if (group) {
            const box = group.parentElement;
            const open = !box.classList.contains('is-open');
            box.classList.toggle('is-open', open);
            group.setAttribute('aria-expanded', open);
            return;
        }
    });

    render();
}
