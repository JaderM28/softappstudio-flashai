<?php

namespace App\Exceptions;

use App\Enums\FailureReason;
use RuntimeException;

/**
 * Base for the third-party failures the app expects to see.
 *
 * Carries two messages: the technical one for the log, and one worth showing on
 * screen. Without the split the user ends up reading raw API JSON.
 */
abstract class ExternalServiceFailed extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $userMessage = '',
        /**
         * What went wrong, as a fact rather than as prose. Retry decisions are
         * made from this and from the status below — never from the message,
         * which carries the provider's own words.
         */
        public readonly FailureReason $reason = FailureReason::Unusable,
        public readonly ?int $status = null,
        /**
         * How long the service asked us to wait, when it said.
         *
         * Worth having because the guess is so much worse than the answer:
         * Gemini's speech tier replies to a 429 with "Please retry in 15.5s",
         * and the fixed five-minute backoff this app used to apply ignored it —
         * turning a quarter-minute pause into a card that took twenty times
         * longer to finish than it needed to.
         */
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return $this->userMessage !== '' ? $this->userMessage : $this->getMessage();
    }

    /**
     * Dig the wait out of whatever the service said.
     *
     * Both shapes are real: a `Retry-After` header is the standard one, and
     * Google puts a sentence in the error body instead.
     */
    protected static function retryAfterFrom(string $body, ?string $header = null): ?int
    {
        if ($header !== null && is_numeric(trim($header))) {
            return max(1, (int) ceil((float) trim($header)));
        }

        if (preg_match('/retry in ([\d.]+)s/i', $body, $matches) === 1) {
            return max(1, (int) ceil((float) $matches[1]));
        }

        return null;
    }

    /**
     * What the user should be told when a service answers with an HTTP error.
     * Quotas and bad keys are the two that actually happen, and they need
     * different answers from the user.
     */
    protected static function reasonFor(string $service, int $status): string
    {
        return match (true) {
            $status === 429 => "{$service}'s free limit is used up for now.",
            in_array($status, [400, 401, 403], true) => "{$service} rejected the API key.",
            $status >= 500 => "{$service} is having trouble right now.",
            default => "{$service} refused the request.",
        };
    }
}
