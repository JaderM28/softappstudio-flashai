<?php

namespace App\Exceptions;

use App\Enums\FailureReason;

/**
 * A picture or an audio clip could not be produced.
 *
 * Never fatal to the sentence itself, which is saved before any of this is
 * attempted. What they decide is whether the note can become a card yet, and
 * whether waiting would change that — see isWorthRetrying().
 */
class MediaFetchFailed extends ExternalServiceFailed
{
    public static function unreachable(string $service, string $detail = ''): self
    {
        return new self(
            trim("{$service} could not be reached. {$detail}"),
            "Could not reach {$service}.",
            FailureReason::Unreachable,
        );
    }

    public static function rejected(string $service, int $status, string $body, ?string $retryAfter = null): self
    {
        return new self(
            "{$service} refused the request (HTTP {$status}): ".mb_substr($body, 0, 500),
            static::reasonFor($service, $status),
            FailureReason::Rejected,
            $status,
            static::retryAfterFrom($body, $retryAfter),
        );
    }

    /**
     * @param  bool  $transient  whether trying again could plausibly help. A reply
     *                           cut short by the network might; a reply that was
     *                           complete and simply empty never will.
     */
    public static function unusableAnswer(string $service, string $detail, bool $transient = false): self
    {
        return new self(
            "{$service} answered with something unusable: {$detail}",
            "{$service} sent back something that could not be used.",
            $transient ? FailureReason::Unreachable : FailureReason::Unusable,
        );
    }

    public static function notConfigured(string $service): self
    {
        return new self(
            "{$service} has no API key configured.",
            "{$service} is not set up yet.",
            FailureReason::NotConfigured,
        );
    }

    public static function nothingFound(string $service, string $query): self
    {
        return new self(
            "{$service} found nothing for \"{$query}\".",
            'No picture matched this sentence.',
            FailureReason::NothingFound,
        );
    }

    /**
     * Whether waiting and trying again could plausibly work. A quota resets; a
     * bad key does not.
     *
     * Decided from the recorded reason and status. It used to be decided by
     * searching the message for "HTTP 429" — and that message includes five
     * hundred characters of the provider's own response body, so an error page
     * that merely mentioned the number would have been retried forever, and a
     * provider rewording its errors would have stopped being retried at all.
     *
     * The signature is unchanged on purpose: the fallback chains and both jobs
     * ask this question and none of them had to learn a new one.
     */
    public function isWorthRetrying(): bool
    {
        return match ($this->reason) {
            FailureReason::Unreachable => true,
            FailureReason::Rejected => $this->status === 429
                || $this->status === 408
                || $this->status >= 500,
            // A key that is not set and a search that found nothing are both
            // answers, not accidents. Waiting changes neither.
            FailureReason::NotConfigured,
            FailureReason::NothingFound,
            FailureReason::Unusable => false,
        };
    }
}
