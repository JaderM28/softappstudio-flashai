<?php

namespace App\Services\Media;

use App\Contracts\SpeechSynthesizer;
use App\Exceptions\MediaFetchFailed;
use App\Support\SynthesizedAudio;
use Illuminate\Support\Facades\Log;

/**
 * Tries several voices in order and returns the first clip produced.
 *
 * The same shape as FallbackImageProvider, for the same reason and with better
 * evidence. Both free speech tiers are real but small, and they are metered on
 * unrelated counters — Cloudflare on neurons a day, Gemini on requests in a
 * window — so a chain of two is not twice the redundancy, it is a service that
 * keeps working through the afternoon one of them has a bad day.
 *
 * This is what the interface existed for. Half of one afternoon's work was lost
 * to Gemini's quota with no way round it; adding a second provider cost one
 * class and one line here.
 */
class FallbackSpeechSynthesizer implements SpeechSynthesizer
{
    /** @var array<int, SpeechSynthesizer> */
    private array $synthesizers;

    public function __construct(SpeechSynthesizer ...$synthesizers)
    {
        $this->synthesizers = $synthesizers;
    }

    public function synthesize(string $text, string $language): SynthesizedAudio
    {
        $failures = [];

        foreach ($this->synthesizers as $synthesizer) {
            try {
                return $synthesizer->synthesize($text, $language);
            } catch (MediaFetchFailed $e) {
                $failures[] = $e;

                Log::info('Speech synthesizer passed', [
                    'synthesizer' => $synthesizer::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        throw $this->mostUseful($failures);
    }

    /**
     * Which failure to report when every voice declined.
     *
     * A quota that resets is worth waiting for and a missing key is not, and
     * the job downstream decides whether to retry by asking exactly this. So a
     * retryable failure is preferred over a permanent one — reporting "not set
     * up" when the real cause was a busy minute would abandon a note that only
     * needed to wait.
     *
     * @param  array<int, MediaFetchFailed>  $failures
     */
    private function mostUseful(array $failures): MediaFetchFailed
    {
        if ($failures === []) {
            return MediaFetchFailed::notConfigured('No speech synthesizer');
        }

        foreach ($failures as $failure) {
            if ($failure->isWorthRetrying()) {
                return $failure;
            }
        }

        return $failures[0];
    }
}
