<?php

namespace App\Providers;

use App\Contracts\ImageProvider;
use App\Contracts\SentenceGenerator;
use App\Contracts\SpeechSynthesizer;
use App\Services\Generation\CachedSentenceGenerator;
use App\Services\Generation\GeminiSentenceGenerator;
use App\Services\Media\CompressedSpeechSynthesizer;
use App\Services\Media\FallbackImageProvider;
use App\Services\Media\GeminiTextToSpeech;
use App\Services\Media\OpenverseImageProvider;
use App\Services\Media\PixabayImageProvider;
use App\Services\Media\UnsplashImageProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Always the real clients. Falling back to fakes when no key is set
        // would quietly fill cards with invented content, which is worse than
        // saying so — each client raises a clear error instead, and a note
        // without a picture is still perfectly studiable.
        $this->app->singleton(
            SentenceGenerator::class,
            fn (): SentenceGenerator => new CachedSentenceGenerator(new GeminiSentenceGenerator),
        );

        // Three libraries in order of how often they answer well. Openverse
        // sits in the middle rather than last because it needs no key: it is
        // the link that still works when nothing is configured.
        $this->app->singleton(ImageProvider::class, fn (): ImageProvider => new FallbackImageProvider(
            new PixabayImageProvider,
            new OpenverseImageProvider,
            new UnsplashImageProvider,
        ));

        // Gemini answers in WAV, and WAV on a 1 GB bucket is the difference
        // between about nineteen months of daily use and about four years. The
        // wrapper degrades to storing the WAV where ffmpeg is missing.
        $this->app->singleton(
            SpeechSynthesizer::class,
            fn (): SpeechSynthesizer => new CompressedSpeechSynthesizer(new GeminiTextToSpeech),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // One limiter for the chain rather than one per provider, because the
        // job cannot know in advance which link will answer. It is set to the
        // most permissive of them: a provider that has run out of room throws,
        // and the chain simply moves to the next.
        RateLimiter::for('images', fn () => Limit::perHour(max(
            (int) config('services.pixabay.per_hour'),
            (int) config('services.openverse.per_hour'),
            (int) config('services.unsplash.per_hour'),
        )));
    }
}
