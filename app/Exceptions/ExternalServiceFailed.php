<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Base for the third-party failures the app expects to see.
 *
 * Carries two messages: the technical one for the log, and one worth showing on
 * screen. Without the split the user ends up reading raw API JSON.
 */
abstract class ExternalServiceFailed extends RuntimeException
{
    public function __construct(string $message, private readonly string $userMessage = '')
    {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return $this->userMessage !== '' ? $this->userMessage : $this->getMessage();
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
