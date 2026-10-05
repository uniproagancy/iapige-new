/**
 * One jQuery, on the window, before anything else in the admin bundle runs.
 *
 * The Vuexy vendor files are UMD: bundled as modules they each resolve
 * `require('jquery')` to this package, but nothing puts the instance on the
 * window — and the template's own scripts, plus every inline snippet in a
 * Blade view, reach for `$` and `jQuery` there. This module is imported first
 * so the global exists by the time the plugins register on it.
 */

import jQuery from 'jquery';

window.$ = window.jQuery = jQuery;

export default jQuery;
