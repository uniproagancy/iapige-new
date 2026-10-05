/**
 * Contact / About / Documents — only the contact form needs behaviour.
 */
import { $, $$, toast, t } from '../core.js';

const form = $('[data-contact-form]');
if (form) {
    form.addEventListener('click', (e) => {
        const topic = e.target.closest('[data-topic]');
        if (!topic) return;
        $$('[data-topic]', form).forEach((t) => {
            t.classList.toggle('is-on', t === topic);
            t.setAttribute('aria-pressed', t === topic);
        });
        form.elements.topic.value = topic.dataset.topic;
    });

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const name = form.elements.name;
        const phone = form.elements.phone;
        if (name.value.trim().length < 2) { toast(t('nameInvalid')); name.focus(); return; }
        if (phone.value.replace(/\D/g, '').length < 9) { toast(t('phoneInvalid')); phone.focus(); return; }

        const btn = form.querySelector('.fsend');
        btn.classList.add('is-sent');
        btn.innerHTML = `<svg class="ic ic-18 ic-check" aria-hidden="true"><use href="#i-check"/></svg>${t('sent')}`;
        clearTimeout(btn._t);
        btn._t = setTimeout(() => { btn.classList.remove('is-sent'); btn.textContent = t('send'); }, 2600);
    });
}
