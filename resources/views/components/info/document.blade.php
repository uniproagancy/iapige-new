@props(['docs', 'active'])

@php $doc = $docs[$active]; @endphp

<section class="pview" aria-label="{{ __('info.text') }}">
    <div class="docs">
        <nav class="doclist" aria-label="{{ __('info.documents') }}">
            @foreach ($docs as $slug => $d)
                <a href="{{ route('info', $slug) }}" @class(['docitem', 'is-on' => $slug === $active]) @if($slug === $active) aria-current="page" @endif>
                    <span class="docitem__name">{{ $d['name'] }}</span>
                </a>
            @endforeach
        </nav>

        <article class="docbody">
            <div class="doc__head">
                <h2 class="doc__title">{{ $doc['title'] }}</h2>
                <p class="doc__intro">{{ $doc['intro'] }}</p>
                <span class="doc__upd">{{ __('info.updated', ['date' => $doc['updated']]) }}</span>
            </div>

            @foreach ($doc['sections'] as $sec)
                <div class="doc__sec">
                    <span class="doc__h">{{ $sec['h'] }}</span>
                    <p class="doc__p">{{ $sec['p'] }}</p>
                    @if (! empty($sec['list']))
                        <div class="doc__list">
                            @foreach ($sec['list'] as $li)
                                <div class="doc__li"><span aria-hidden="true"></span><span>{{ $li }}</span></div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach

            <div class="doc__foot">
                <span>{{ __('info.doc_questions') }}</span>
                <a class="doc__mail" href="mailto:legal@iapi.ge">legal@iapi.ge</a>
            </div>
        </article>
    </div>
</section>
