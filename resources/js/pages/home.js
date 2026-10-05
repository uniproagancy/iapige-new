/**
 * Home page — hero slider and the weekly-deal countdown.
 */
import { $, $$ } from '../core.js';

/* ------------------------------------------------------------------ hero */

const hero = $('[data-hero]');
if (hero) {
    const slides = $$('.hero__slide', hero);
    const dots = $$('.hero__dot', hero);
    let index = 0;
    let timer;

    const go = (i) => {
        index = (i + slides.length) % slides.length;
        slides.forEach((s, si) => {
            s.classList.toggle('is-active', si === index);
            s.setAttribute('aria-hidden', si === index ? 'false' : 'true');
        });
        dots.forEach((d, di) => {
            d.classList.toggle('is-active', di === index);
            d.setAttribute('aria-selected', di === index);
        });
    };

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const restart = () => {
        clearInterval(timer);
        if (reduced) return;
        timer = setInterval(() => {
            if (!document.body.classList.contains('is-locked') && !document.hidden) go(index + 1);
        }, 6000);
    };

    hero.addEventListener('click', (e) => {
        const step = e.target.closest('[data-hero-step]');
        if (step) { go(index + Number(step.dataset.heroStep)); restart(); return; }
        const dot = e.target.closest('[data-dot]');
        if (dot) { go(Number(dot.dataset.dot)); restart(); }
    });

    /* swipe */
    let x0 = null;
    hero.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; }, { passive: true });
    hero.addEventListener('touchend', (e) => {
        if (x0 === null) return;
        const dx = e.changedTouches[0].clientX - x0;
        if (Math.abs(dx) > 40) { go(index + (dx < 0 ? 1 : -1)); restart(); }
        x0 = null;
    }, { passive: true });

    /* keyboard, when nothing else has focus */
    document.addEventListener('keydown', (e) => {
        if (document.body.classList.contains('is-locked') || e.target.closest('input, textarea, select')) return;
        if (e.key === 'ArrowLeft') { go(index - 1); restart(); }
        if (e.key === 'ArrowRight') { go(index + 1); restart(); }
    });

    restart();
}

/* ------------------------------------------------------------------ countdown */

const countdown = $('[data-deadline]');
if (countdown) {
    const deadline = new Date(countdown.dataset.deadline).getTime();
    const pad = (n) => String(n).padStart(2, '0');
    const tick = () => {
        let ms = Math.max(0, deadline - Date.now());
        const d = Math.floor(ms / 864e5); ms -= d * 864e5;
        const h = Math.floor(ms / 36e5);  ms -= h * 36e5;
        const m = Math.floor(ms / 6e4);
        $('[data-cd="d"]', countdown).textContent = pad(d);
        $('[data-cd="h"]', countdown).textContent = pad(h);
        $('[data-cd="m"]', countdown).textContent = pad(m);
    };
    tick();
    setInterval(tick, 30000);
}
