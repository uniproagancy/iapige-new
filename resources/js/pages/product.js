/**
 * Product page — gallery and tabs only.
 * Quantity, options, bundle, call-back and add-to-cart are Livewire components.
 */
import { $, $$ } from '../core.js';

/* ------------------------------------------------------------------ gallery */

const gallery = $('[data-gallery]');
if (gallery) {
    const images = JSON.parse(gallery.dataset.gallery);
    const main = $('#galImg');
    let index = 0;

    const go = (i) => {
        index = (i + images.length) % images.length;
        main.classList.remove('is-broken');
        main.src = images[index];
        main.style.animation = 'none'; void main.offsetWidth; main.style.animation = '';
        $$('.gal__dot', gallery).forEach((d, di) => d.classList.toggle('is-on', di === index));
        $$('.thumb', gallery).forEach((d, di) => d.classList.toggle('is-on', di === index));
    };

    gallery.addEventListener('click', (e) => {
        const step = e.target.closest('[data-gal-step]');
        if (step) { go(index + Number(step.dataset.galStep)); return; }
        const pick = e.target.closest('[data-gal-go]');
        if (pick) go(Number(pick.dataset.galGo));
    });

    document.addEventListener('keydown', (e) => {
        if (document.body.classList.contains('is-locked') || e.target.closest('input, textarea, select')) return;
        if (e.key === 'ArrowLeft') go(index - 1);
        if (e.key === 'ArrowRight') go(index + 1);
    });
}

/* ------------------------------------------------------------------ tabs */

document.addEventListener('click', (e) => {
    const tab = e.target.closest('[data-tab]');
    if (!tab) return;

    const name = tab.dataset.tab;
    $$('.tabpill').forEach((p) => {
        p.classList.toggle('is-on', p.dataset.tab === name);
        p.setAttribute('aria-selected', p.dataset.tab === name);
    });
    $$('[data-tab-panel]').forEach((p) => { p.hidden = p.dataset.tabPanel !== name; });

    if (tab.hasAttribute('data-scroll-tabs')) $('#tabs')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
});
