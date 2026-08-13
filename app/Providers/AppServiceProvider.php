<?php

namespace App\Providers;

use App\Contracts\SentenceGenerator;
use App\Services\Generation\CachedSentenceGenerator;
use App\Services\Generation\GeminiSentenceGenerator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Always the real one. Falling back to the fake when no key is set
        // would quietly fill cards with invented sentences, which is worse
        // than saying so — GeminiSentenceGenerator raises a clear error, and
        // the add screen hides the generate box entirely without a key.
        $this->app->singleton(
            SentenceGenerator::class,
            fn (): SentenceGenerator => new CachedSentenceGenerator(new GeminiSentenceGenerator),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
