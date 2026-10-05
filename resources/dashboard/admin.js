/**
 * ELIO admin — one bundle, built by Vite.
 *
 * The Vuexy template is jQuery-based, so the vendor bundle has to run before
 * app.js and app-menu.js and its globals must stay on window: the template's
 * own scripts, and any inline snippet in a Blade view, reach for them there.
 */

import './js/vendors/vendors.min.js';        // jQuery + the plugins the template expects
import './js/vendors/jquery.sticky.js';
import './js/vendors/feather-icons.min.js';
import './js/vendors/toastr.min.js';
import './js/vendors/sweetalert2.all.min.js';

import './js/core/app-menu.js';
import './js/core/app.js';

/* the template and our Blade views both call these by name */
window.$ = window.jQuery = window.jQuery ?? jQuery;
window.feather = window.feather ?? feather;
window.toastr = window.toastr ?? toastr;
window.Swal = window.Swal ?? Swal;

toastr.options = {
    closeButton: true,
    progressBar: true,
    positionClass: 'toast-top-right',
    timeOut: 4000,
};

/* ------------------------------------------------------------------ icons */

/** Feather draws every icon in the template, and Livewire keeps replacing DOM. */
function drawIcons() {
    window.feather?.replace({ width: 14, height: 14 });
}

window.addEventListener('load', drawIcons);

/* ------------------------------------------------------------------ Livewire bridge */

document.addEventListener('livewire:initialized', () => {
    Livewire.hook('morph.updated', drawIcons);

    /* one channel for every component: $this->dispatch('toast', message: '…', type: 'error') */
    Livewire.on('toast', (event) => {
        const data = Array.isArray(event) ? event[0] : event;
        (toastr[data.type] ?? toastr.success)(data.message, data.title ?? null);

        if (data.redirect_url) {
            setTimeout(() => (window.location.href = data.redirect_url), 1500);
        }
    });

    /* kept for components that still speak the older event names */
    Livewire.on('ui:success', (d) => toastr.success((Array.isArray(d) ? d[0] : d).message));
    Livewire.on('ui:error', (d) => toastr.error((Array.isArray(d) ? d[0] : d).message));
});

/* ------------------------------------------------------------------ confirmations */

/**
 * <button data-confirm="Delete this?" data-confirm-action="delete" data-confirm-arg="12">
 * A Livewire action runs only after the dialog is accepted, so wire:click stays off.
 */
document.addEventListener('click', (e) => {
    const button = e.target.closest('[data-confirm]');

    if (!button) return;

    e.preventDefault();
    e.stopPropagation();

    const root = button.closest('[wire\\:id]');
    const action = button.dataset.confirmAction;
    const arg = button.dataset.confirmArg;

    Swal.fire({
        title: button.dataset.confirmTitle ?? button.dataset.confirm,
        text: button.dataset.confirmTitle ? button.dataset.confirm : undefined,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: window.ELIO_ADMIN?.yes ?? 'დიახ',
        cancelButtonText: window.ELIO_ADMIN?.cancel ?? 'გაუქმება',
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            confirmButton: 'btn btn-danger',
            cancelButton: 'btn btn-outline-secondary ms-1',
        },
        buttonsStyling: false,
    }).then((result) => {
        if (result.isConfirmed && root && action) {
            const component = Livewire.find(root.getAttribute('wire:id'));
            arg !== undefined ? component.call(action, arg) : component.call(action);
        }
    });
});
