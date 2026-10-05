/**
 * Dialogs and notifications, in one place so the rest of the app never talks
 * to the libraries directly — swapping either one stays a single-file change.
 */
import Swal from 'sweetalert2';
import Toastify from 'toastify-js';
import 'toastify-js/src/toastify.css';

const brand = getComputedStyle(document.documentElement).getPropertyValue('--red-bright').trim() || '#FF6900';
const ink = getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#16181C';

/** Ask before something irreversible. Resolves to true/false. */
export async function confirm(message, options = {}) {
    const result = await Swal.fire({
        title: options.title ?? message,
        text: options.title ? message : undefined,
        icon: options.icon ?? 'warning',
        showCancelButton: true,
        confirmButtonText: options.confirmText ?? window.ELIO?.i18n?.yes ?? 'დიახ',
        cancelButtonText: options.cancelText ?? window.ELIO?.i18n?.cancel ?? 'გაუქმება',
        confirmButtonColor: brand,
        cancelButtonColor: '#E3E6E9',
        reverseButtons: true,
        focusCancel: true,
        customClass: { popup: 'elio-swal' },
    });

    return result.isConfirmed;
}

/** @param {'success'|'error'|'info'} type */
export function toast(message, type = 'success') {
    Toastify({
        text: message,
        duration: 2600,
        gravity: 'bottom',
        position: 'center',
        close: false,
        stopOnFocus: true,
        style: {
            background: type === 'error' ? '#DC2626' : ink,
            borderRadius: '999px',
            padding: '12px 22px',
            fontSize: '14px',
            boxShadow: '0 12px 30px rgba(22,24,28,.22)',
        },
    }).showToast();
}