/**
 * A Cloudflare Worker that fetches zoommer.ge on the shop's behalf.
 *
 * Why this exists: Cloudflare answers a datacentre address with 403 whatever
 * cookie it carries — it is refusing the caller, not the request. Measured
 * against the live API, the only cookie Zoommer actually checks is
 * zoommer-access_token (dropping cf_clearance still returns 200, dropping the
 * token returns 401), so this is not a cookie problem and no amount of
 * rotating one fixes it. A Worker runs on an address Cloudflare does not
 * refuse, which is the same trick the Elite driver has always used.
 *
 * It forwards the caller's own headers rather than holding credentials, so the
 * access token stays in the shop's .env and this file carries no secret except
 * the one that stops strangers using it as an open proxy.
 *
 * Deploy
 *   1. Cloudflare dashboard -> Workers & Pages -> Create -> Worker
 *   2. Paste this file, Deploy
 *   3. Settings -> Variables -> add a secret named PROXY_TOKEN (any long
 *      random string). Without it the Worker refuses every request.
 *   4. In the shop's .env:
 *        ZOOMMER_WORKER_URL=https://<your-worker>.workers.dev
 *        ZOOMMER_WORKER_TOKEN=<the same PROXY_TOKEN>
 *      then: php artisan config:clear
 *
 * Leaving ZOOMMER_WORKER_URL unset makes the shop talk to zoommer.ge directly,
 * which is what you want on a machine whose address is not blocked.
 */

/** Only these hosts may be fetched, so the Worker cannot become an open proxy. */
const ALLOWED_HOSTS = ['zoommer.ge', 'www.zoommer.ge'];

/** Headers that belong to the hop, not to the request being forwarded. */
const HOP_BY_HOP = new Set([
  'host',
  'connection',
  'keep-alive',
  'transfer-encoding',
  'upgrade',
  'proxy-authorization',
  'proxy-connection',
  'te',
  'trailer',
  'cf-connecting-ip',
  'cf-ipcountry',
  'cf-ray',
  'cf-visitor',
  'x-forwarded-for',
  'x-forwarded-proto',
  'x-real-ip',
]);

export default {
  async fetch(request, env) {
    const params = new URL(request.url).searchParams;

    if (!env.PROXY_TOKEN) {
      return json({ error: 'PROXY_TOKEN is not set on this Worker' }, 500);
    }

    // compared whole, so a partial guess tells an attacker nothing
    if (params.get('token') !== env.PROXY_TOKEN) {
      return json({ error: 'bad or missing token' }, 401);
    }

    const target = params.get('url');

    if (!target) {
      return json({ error: 'url is required' }, 400);
    }

    let url;

    try {
      url = new URL(target);
    } catch {
      return json({ error: 'url is not a valid address' }, 400);
    }

    if (url.protocol !== 'https:' || !ALLOWED_HOSTS.includes(url.hostname)) {
      return json({ error: `only https on ${ALLOWED_HOSTS.join(', ')} is allowed` }, 403);
    }

    // the caller's headers go on, so its cookie and user agent are the ones
    // Zoommer sees — this Worker holds no credentials of its own
    const headers = new Headers();

    for (const [name, value] of request.headers) {
      if (!HOP_BY_HOP.has(name.toLowerCase())) {
        headers.set(name, value);
      }
    }

    headers.set('Referer', 'https://zoommer.ge/');
    headers.set('Origin', 'https://zoommer.ge');

    let upstream;

    try {
      upstream = await fetch(url.toString(), {
        method: 'GET',
        headers,
        redirect: 'follow',
      });
    } catch (e) {
      return json({ error: `fetch failed: ${e.message}` }, 502);
    }

    /*
     * The status is passed through untouched. The shop tells a refusal apart
     * from a missing product by exactly this number, so flattening everything
     * to 200 here would put back the silence this whole change removed.
     */
    return new Response(upstream.body, {
      status: upstream.status,
      headers: {
        'content-type': upstream.headers.get('content-type') ?? 'application/json',
        'cache-control': 'no-store',
      },
    });
  },
};

function json(body, status) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  });
}
