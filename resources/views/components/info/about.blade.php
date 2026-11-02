<section class="pview" aria-label="{{ __('info.about') }}">
    <div class="about">
        <div class="about__media">
            <img src="https://picsum.photos/seed/iapi-team/900/760" alt="{{ __('info.team') }}" loading="lazy">
        </div>
        <div class="about__body">
            @foreach ((array) __('info.about_text') as $paragraph)
                <p class="about__text">{{ $paragraph }}</p>
            @endforeach
        </div>
    </div>
</section>
