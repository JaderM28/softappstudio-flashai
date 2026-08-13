<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Carries two messages: the technical one for the log, and one worth showing
 * on screen. Without the split the user ends up reading raw API JSON.
 *
 * Every user-facing message points back at typing the sentence by hand, since
 * that always works and is the whole reason manual entry was built first.
 */
class GenerationFailed extends RuntimeException
{
    private const FALLBACK = ' You can still write it yourself below.';

    public function __construct(string $message, private readonly string $userMessage = '')
    {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return $this->userMessage !== '' ? $this->userMessage : $this->getMessage();
    }

    public static function unreachable(string $service, string $detail = ''): self
    {
        return new self(
            trim("{$service} could not be reached. {$detail}"),
            "Could not reach {$service}.".self::FALLBACK,
        );
    }

    public static function rejected(string $service, int $status, string $body): self
    {
        return new self(
            "{$service} refused the request (HTTP {$status}): ".mb_substr($body, 0, 500),
            match (true) {
                $status === 429 => "{$service}'s free daily limit is used up. Try again tomorrow.".self::FALLBACK,
                in_array($status, [400, 401, 403], true) => "{$service} rejected the API key. Check GEMINI_API_KEY in your .env.".self::FALLBACK,
                $status >= 500 => "{$service} is having trouble right now.".self::FALLBACK,
                default => "{$service} refused the request.".self::FALLBACK,
            },
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
