@props(['cards', 'topics'])

<section class="pview" aria-label="{{ __('info.contact') }}">
    <div class="ccards">
        @foreach ($cards as $c)
            <a class="ccard" href="{{ $c['href'] }}">
                <span class="ccard__k">{{ $c['k'] }}</span>
                <span class="ccard__v">{{ $c['v'] }}</span>
                <span class="ccard__n">{{ $c['note'] }}</span>
            </a>
        @endforeach
    </div>
</section>
