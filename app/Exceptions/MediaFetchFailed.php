<?php

namespace App\Exceptions;

/**
 * A picture or an audio clip could not be produced.
 *
 * Unlike a failed sentence, these are never fatal: a note with text but no
 * picture is perfectly studiable, which is why asset status is tracked per
 * asset rather than once per note.
 */
class MediaFetchFailed extends ExternalServiceFailed
{
    public static function unreachable(string $service, string $detail = ''): self
    {
        return new self(
            trim("{$service} could not be reached. {$detail}"),
            "Could not reach {$service}.",
        );
    }

    public static function rejected(string $service, int $status, string $body): self
    {
        return new self(
            "{$service} refused the request (HTTP {$status}): ".mb_substr($body, 0, 500),
            static::reasonFor($service, $status),
        );
    }

    public static function unusableAnswer(string $service, string $detail): self
    {
        return new self(
            "{$service} answered with something unusable: {$detail}",
            "{$service} sent back something that could not be used.",
        );
    }

    public static function notConfigured(string $service): self
    {
        return new self(
            "{$service} has no API key configured.",
            "{$service} is not set up yet.",
        );
    }

    public static function nothingFound(string $service, string $query): self
    {
        return new self(
            "{$service} found nothing for \"{$query}\".",
            'No picture matched this sentence.',
        );
    }

    /**
     * Whether waiting and trying again could plausibly work. A quota resets; a
     * bad key does not.
     */
    public function isWorthRetrying(): bool
    {
        return str_contains($this->getMessage(), 'HTTP 429')
            || str_contains($this->getMessage(), 'could not be reached')
            || preg_match('/HTTP 5\d\d/', $this->getMessage()) === 1;
    }
}
