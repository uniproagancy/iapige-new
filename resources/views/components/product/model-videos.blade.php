@props(['model'])

@php($modelVideos = app(\App\Support\ProductMedia::class)->videos($model))

@if ($modelVideos)
    <section class="product-model-videos" aria-label="{{ __('media.model_videos') }}">
        <h2 class="product-model-videos__heading">{{ __('media.model_videos') }}</h2>
        <div class="product-model-videos__grid{{ count($modelVideos) === 1 ? ' product-model-videos__grid--single' : '' }}">
            @foreach ($modelVideos as $clip)
                <div class="product-model-videos__card">
                    <video controls playsinline preload="metadata" poster="{{ $clip['poster'] }}"
                        aria-label="{{ __('media.model_video', ['number' => $loop->iteration]) }}">
                        <source src="{{ $clip['url'] }}" type="video/mp4">
                        {{ __('media.video_not_supported') }}
                        <a href="{{ $clip['url'] }}">{{ __('media.open_video') }}</a>
                    </video>
                </div>
            @endforeach
        </div>
    </section>

    <style>
        .product-model-videos { margin: 28px 0; }
        .product-model-videos__heading { margin: 0 0 16px; font-size: 22px; font-weight: 600; }
        .product-model-videos__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        .product-model-videos__card { display: flex; justify-content: center; overflow: hidden; padding: 12px; border: 1px solid #e9ebed; border-radius: 20px; background: #f6f7f8; }
        .product-model-videos video { display: block; width: 100%; max-width: 320px; height: auto; max-height: 574px; aspect-ratio: 464 / 832; border-radius: 12px; object-fit: contain; background: #111; }
        .product-model-videos__grid--single { grid-template-columns: minmax(0, 1fr); width: 100%; max-width: 446px; margin-inline: auto; }
        .product-model-videos__grid--single .product-model-videos__card { box-sizing: border-box; width: 100%; }
        .product-model-videos__grid--single video { max-width: 420px; max-height: none; aspect-ratio: 9 / 16; }
        @media (max-width: 600px) {
            .product-model-videos__grid { grid-template-columns: minmax(0, 1fr); gap: 14px; }
            .product-model-videos__heading { font-size: 20px; }
        }
    </style>
@endif
