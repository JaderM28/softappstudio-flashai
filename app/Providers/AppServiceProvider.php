<?php

namespace App\Providers;

use App\Contracts\ImageProvider;
use App\Contracts\SentenceGenerator;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\SpeechTranscriber;
use App\Services\Generation\CachedSentenceGenerator;
use App\Services\Generation\GeminiSentenceGenerator;
use App\Services\Media\CloudflareTextToSpeech;
use App\Services\Media\CloudflareTranscriber;
use App\Services\Media\CompressedSpeechSynthesizer;
use App\Services\Media\FallbackSpeechSynthesizer;
use App\Services\Media\FallbackImageProvider;
use App\Services\Media\GeminiTextToSpeech;
use App\Services\Media\OpenverseImageProvider;
use App\Services\Media\PixabayImageProvider;
use App\Services\Media\UnsplashImageProvider;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
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
        // saying so — each client raises a clear error instead, and the note
        // waits in the tray until somebody can do something about it.
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

        // Cloudflare first, on measurements: one second against six, an MP3
        // already encoded, and about 140 notes a day inside the free tier
        // against a Gemini limit that one afternoon's testing exhausted.
        //
        // Gemini stays behind it because the two are metered on unrelated
        // counters, so the pair keeps working through an afternoon either one
        // has a bad day. The compressor still wraps both: Cloudflare's MP3
        // passes through it untouched, and Gemini's WAV is ten times the size
        // on a 1 GB bucket if it does not.
        // Same endpoint, same token, same free tier as the voice.
        $this->app->singleton(SpeechTranscriber::class, fn (): SpeechTranscriber => new CloudflareTranscriber);

        $this->app->singleton(
            SpeechSynthesizer::class,
            fn (): SpeechSynthesizer => new CompressedSpeechSynthesizer(
                new FallbackSpeechSynthesizer(
                    new CloudflareTextToSpeech,
                    new GeminiTextToSpeech,
                ),
            ),
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

        $this->defineDiagnosticsGate();
    }

    /**
     * Who may open /system and spend real API quota by pressing a button.
     *
     * Local is open, because there the only person who can reach it is whoever
     * is running it. Anywhere else it is the listed addresses, or — with the
     * list empty, which is how it will actually be deployed — the account that
     * registered first. Never "any authenticated user": the page calls paid
     * services on demand, and this app lets anyone sign up.
     */
    private function defineDiagnosticsGate(): void
    {
        Gate::define('view-diagnostics', function (User $user): bool {
            if ($this->app->isLocal()) {
                return true;
            }

            $allowed = array_filter(array_map(
                'trim',
                explode(',', (string) config('flashai.diagnostics.emails')),
            ));

            if ($allowed !== []) {
                return in_array($user->email, $allowed, true);
            }

            return $user->id === Cache::remember(
                'diagnostics-owner-id',
                now()->addHour(),
                fn () => User::query()->min('id'),
            );
        });
    }
}
