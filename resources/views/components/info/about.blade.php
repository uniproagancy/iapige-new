@props(['values'])

<section class="pview" aria-label="{{ __('info.about') }}">
    <div class="about">
        <div class="about__media">
            <img src="https://picsum.photos/seed/elio-team/900/760" alt="{{ __('info.team') }}" loading="lazy">
        </div>
        <div class="about__body">
            <p class="about__text">{!! __('info.about_text') !!}</p>
        </div>
    </div>
</section>
