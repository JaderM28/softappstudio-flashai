<?php

namespace App\Providers;

use App\Contracts\ImageProvider;
use App\Contracts\SentenceGenerator;
use App\Contracts\SpeechSynthesizer;
use App\Services\Generation\CachedSentenceGenerator;
use App\Services\Generation\GeminiSentenceGenerator;
use App\Services\Media\GoogleTextToSpeech;
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

        $this->app->singleton(ImageProvider::class, UnsplashImageProvider::class);
        $this->app->singleton(SpeechSynthesizer::class, GoogleTextToSpeech::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Unsplash allows 50 requests an hour on the free tier. The image job
        // takes this before running, so a batch of sentences drains over hours
        // instead of failing after the first twenty.
        RateLimiter::for('unsplash', fn () => Limit::perHour(
            (int) config('services.unsplash.per_hour')
        ));
    }
}
