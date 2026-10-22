/*
 | The checkout form is taller than a phone screen. When it refused to submit,
 | the complaint appeared next to a field the shopper could not see — the page
 | simply stayed where the submit button was. This takes them to it.
 */

const find = (field) =>
    document.querySelector(`[data-field="${field}"]`) ??
    document.querySelector('.ferror')?.closest('[data-field]') ??
    document.querySelector('.ferror');

window.addEventListener('form-error', (event) => {
    /* Livewire has answered, but the error text is painted on the next frame. */
    requestAnimationFrame(() => {
        const target = find(event.detail?.field);

        if (!target) {
            return;
        }

        target.scrollIntoView({ behavior: 'smooth', block: 'center' });

        target.querySelector('input, textarea, select')?.focus({ preventScroll: true });
    });
});
