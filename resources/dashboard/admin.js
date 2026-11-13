/**
 * IAPI.GE admin — our own part of it, built by Vite.
 *
 * The Vuexy template's scripts are not in here. They are written as plain
 * scripts that leave their names on the window -- "Unison = function(){…}"
 * with no var in front -- and bundled into a module, which is always strict,
 * that line throws. vendors.min.js stopped there, app-menu.js never ran, and
 * on a phone the menu stayed hidden with nothing to open it. They are loaded
 * the way the template ships them, from public/dashboard-assets/js, before
 * this bundle; see layouts/admin.blade.php.
 */

// bundled, these export rather than leak a global — so we name what they give
import toastr from './js/vendors/toastr.min.js';
import Swal from './js/vendors/sweetalert2.all.min.js';

/* the template and our Blade views both call these by name */
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

/*
 * Handled by Livewire's own wire:confirm, not by anything here.
 *
 * There used to be a bridge: data-confirm on the button, a dialog here, and
 * the action called afterwards through $wire. It called it as $wire.call(name),
 * and Livewire 4's proxy has no "call" — so the name was read as a server
 * method, the component was asked to run one called "call", there is none, and
 * the request was refused in silence. Every confirmed action in the admin went
 * that way: deleting a product, deleting a batch of them, marking an order
 * paid, closing an account.
 *
 * Fixing the call was not enough to be worth keeping. A delete button has no
 * business depending on a bundle of ours loading and a global of theirs being
 * where we left it, so the buttons now carry wire:click and wire:confirm and
 * Livewire does the asking. The dialog is the browser's own rather than
 * SweetAlert — plainer, and it cannot fail to appear.
 */
