# Alta JSON exporter

Standalone Chrome/Edge 120+ extension for saving Alta API JSON through ordinary browser tab navigation. Version 0.3.0 accepts an owner-issued signed stock-feed link without an admin login, and requests Georgian Alta API responses with `Accept-Language: ka`. The icon opens `panel.html`; the background script only opens or focuses that panel. Collection runs in the open panel, with no background messaging, schedule, automatic import, or catalogue POST.

## Use

1. Unzip into a permanent folder. Open `edge://extensions/` or `chrome://extensions/`, enable Developer mode, select Load unpacked and choose the folder containing `manifest.json`. Open the panel from its IAPI icon. No Node.js, PHP, package installation or IAPI account is required for the file/code workflow. Georgian instructions are in `INSTALL-KA.txt`.
2. Paste the owner's stock-feed link into **B2B სიის წვდომის ბმული** and click **სიის ჩატვირთვა**. IAPI host permission is requested directly by that click; no admin session is needed. Only the exact HTTPS path `/feeds/alta-stock.json` on `iapi.ge` is accepted, with exactly one UUID `version` and one64-hex `signature` parameter. Extra parameters, fragments, credentials, foreign hosts and nonstandard ports are rejected. The link is saved only in this extension's local storage and is not included in product exports or the share ZIP. Treat the link as private read access to supplier stock: its owner can revoke it by issuing a replacement.
   Alternatively, paste exact item codes or select your own B2B JSON feed file. `examples/product-codes.txt` contains public trial codes. `examples/b2b-template.json` contains synthetic placeholders: replace them and the quantities with real B2B data. These modes do not require IAPI access.
3. Leave the limit at3 for a sample; `0` selects every supplied item. Pasted codes select a subset of a loaded feed. Without a feed, pasted codes can still collect raw results.
4. Start collection. Keep the panel open and let its own helper tab navigate normally. Every navigation is paced by at least one second; at most five candidate product IDs are checked. A details barcode must exactly match the B2B item code.
5. Pause or resume from the saved product position. A challenge, unavailable page, invalid JSON, or timeout pauses the current item and preserves earlier results. There is no automatic challenge interaction or retry loop.
6. Download **საწყისი JSON** for the complete original matched details responses. Download **იმპორტის JSON** for a top-level array accepted by the existing manual uploader. This copy preserves the full response and replaces only `product.storageQuantity` with known B2B quantity, including0, and `availabilityInStores` with an empty array. Unknown B2B quantity or unusable retail price/name blocks the import download; the raw download remains available. Loading a feed can supply quantities for previously collected pasted codes.

Raw response bodies are stored per product in IndexedDB rather than extension local storage. Only job progress, exact codes and B2B quantities are stored in `chrome.storage.local`. The current job survives panel closure. Starting a new job replaces the current panel job; download existing partial results first if they are needed.

Signed feeds use schema `iapi.supplier-stock.v1`, supplier `alta`, source `supplier_stocks`, an ISO `synced_at`, matching `count`, and all item/quantity rows including0. Approved error responses never replace a saved feed. Source and snapshot time are retained with the feed and job. Signed stock older than26hours, invalid timestamps and timestamps more than5minutes in the future block collection, resume and import export. Raw product downloads remain available. Load a fresh feed to continue; replacing a feed clears quantities for items absent from it. Generic manually supplied file/code data has no server freshness guarantee. Pre0.3 quantities with unknown provenance require reloading the feed or file before import.

Before each Alta search/details navigation, the reader installs a tab-specific session rule setting `Accept-Language: ka`. The rule matches only the owned helper tab, main-frame requests and the exact two Alta API routes. Rule installation is awaited before the first API request; failure stops collection instead of silently requesting the default language. IAPI navigation removes this extension's own rule. It uses Chrome's documented [session rules and header modification API](https://developer.chrome.com/docs/extensions/reference/api/declarativeNetRequest).

API responses are preserved exactly; the header requests Georgian but cannot translate missing source fields. The browser locale remains unchanged. Start a **new collection** after updating to recollect older English responses: Resume intentionally reuses already saved raw data. Photos remain URLs in JSON; no image binaries are downloaded.

## Boundaries

Required host permission is limited to `https://alta.ge/*`; `https://iapi.ge/*` is optional and requested only by the signed-feed action. Denial leaves file/code collection available and makes no IAPI request. This uses Chrome's documented [optional permissions flow](https://developer.chrome.com/docs/extensions/reference/api/permissions). Permissions are `scripting`, `storage` and `declarativeNetRequestWithHostAccess` for the scoped language header. No global `tabs`, `downloads`, cookies, debugger, proxy or web-request permission is requested. It reads rendered JSON text from its own helper tab. It does not call the Alta API with fetch, inspect hidden framework state, export authentication data, solve challenges, or upload products.

The panel uses a browser Web Lock to prevent concurrent collectors across panels. Navigation and script-injection waits are bounded. Results are checkpointed after each product. Downloads use a Blob and anchor, with no download permission.

The share ZIP contains runtime files, icons, Georgian instructions and examples. It excludes tests, the Laravel application, account credentials, saved browser data and the owner's B2B feed. Keep the installed folder in place when updating; replace its files and use Reload on the extensions page to preserve extension identity and stored work.

Run local fixtures from the repository root:

```sh
node --test tools/alta-json-exporter/tests/*.test.mjs
```
