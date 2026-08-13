<?php

namespace App\Exceptions;

/**
 * Sentence generation could not produce a note.
 *
 * Every user-facing message points back at typing the sentence by hand, since
 * that always works and is the whole reason manual entry was built first.
 */
class GenerationFailed extends ExternalServiceFailed
{
    private const FALLBACK = ' You can still write it yourself below.';

    public static function unreachable(string $service, string $detail = ''): self
    {
        return new self(
            trim("{$service} could not be reached. {$detail}"),
            "Could not reach {$service}.".self::FALLBACK,
        );
    }

    public static function rejected(string $service, int $status, string $body): self
    {
        $reason = match ($status) {
            429 => "{$service}'s free daily limit is used up. Try again tomorrow.",
            400, 401, 403 => "{$service} rejected the API key. Check GEMINI_API_KEY in your .env.",
            default => static::reasonFor($service, $status),
        };

        return new self(
            "{$service} refused the request (HTTP {$status}): ".mb_substr($body, 0, 500),
            $reason.self::FALLBACK,
        );
    }

    public static function unusableAnswer(string $service, string $detail): self
    {
        return new self(
            "{$service} answered with something unusable: {$detail}",
            "{$service} sent back something that could not be read. Try again.".self::FALLBACK,
        );
    }

    public static function notConfigured(string $service): self
    {
        return new self(
            "{$service} has no API key configured.",
            'Sentence generation is not set up yet.'.self::FALLBACK,
        );
    }
}
