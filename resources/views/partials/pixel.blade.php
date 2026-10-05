{{--
    Meta pixel, the browser half.

    It is not loaded until the cookie banner has been answered with "all": the
    shop asks before it tracks, so "necessary only" has to actually mean that.
    The same choice is mirrored into a cookie, which is what App\Services\
    Facebook\Pixel reads before it sends anything from the server.

    Every event carries an eventID. The server sends the same event with the
    same id, and Meta keeps one of the two — that is what makes the pair add up
    to one conversion instead of two.
--}}
@php
    $pixelId = config('services.facebook.pixel_id');

    /*
     | Advanced matching. These go over in plain text on purpose: the pixel
     | hashes them in the browser before anything leaves it, and pre-hashing
     | here would hash them twice and match nobody.
     */
    $pixelMatch = [];

    if ($pixelUser = auth()->user()) {
        $pixelPhone = preg_replace('/\D/', '', (string) $pixelUser->phone);

        $pixelMatch = array_filter([
            'em'          => $pixelUser->email,
            'ph'          => strlen((string) $pixelPhone) >= 9 ? $pixelPhone : null,
            'fn'          => $pixelUser->firstName(),
            'external_id' => (string) $pixelUser->id,
            'country'     => 'ge',
        ]);
    }
@endphp

@if ($pixelId)
    <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window,document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');

        window.IAPI_PIXEL = {
            id: @json($pixelId),
            match: @json((object) $pixelMatch),
            pageViewId: @json($fbEventId ?? null),
            started: false,
            queue: [],
        };

        /* Events raised before consent wait here rather than being lost. */
        window.pixelTrack = function (event, data, eventId) {
            if (!window.IAPI_PIXEL.started) {
                window.IAPI_PIXEL.queue.push([event, data, eventId]);
                return;
            }

            fbq('track', event, data || {}, eventId ? { eventID: eventId } : undefined);
        };

        function pixelStart() {
            const p = window.IAPI_PIXEL;

            if (p.started) return;

            p.started = true;

            Object.keys(p.match).length ? fbq('init', p.id, p.match) : fbq('init', p.id);
            fbq('track', 'PageView', {}, p.pageViewId ? { eventID: p.pageViewId } : undefined);

            p.queue.splice(0).forEach(([event, data, eventId]) => window.pixelTrack(event, data, eventId));
        }

        /* already answered on an earlier visit, or answered just now */
        if (document.cookie.split('; ').includes('cookie_consent=all')) {
            pixelStart();
        } else {
            window.addEventListener('consent:all', pixelStart);
        }

        /*
         | Livewire components raise their events on the server, where the page
         | has already been sent — so they come back over the wire and are
         | fired here with the id the server used.
         */
        document.addEventListener('livewire:init', () => {
            Livewire.on('pixel', (payload) => {
                const e = Array.isArray(payload) ? payload[0] : payload;
                window.pixelTrack(e.event, e.data, e.id);
            });
        });
    </script>

    @stack('pixel')

    <noscript>
        <img height="1" width="1" style="display:none" alt=""
             src="https://www.facebook.com/tr?id={{ $pixelId }}&ev=PageView&noscript=1">
    </noscript>
@endif
