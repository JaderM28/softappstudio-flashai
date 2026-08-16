<?php

namespace App\Support;

use App\Enums\ProbeStatus;
use App\Exceptions\ExternalServiceFailed;
use Throwable;

/**
 * What one diagnostic probe found.
 *
 * Both messages are kept, and on purpose. `detail` is the technical one, whole
 * and uncut — the diagnostics screen is for whoever owns the installation, and
 * trimming the reason down to something reassuring is precisely how this
 * project spent weeks not knowing that its audio had never worked.
 */
readonly class ProbeResult
{
    /**
     * @param  string  $group  'image' | 'audio' | 'sentence' | 'infra'
     * @param  array<string, mixed>  $payload  anything the screen can show: a url, a path, byte counts
     */
    public function __construct(
        public string $service,
        public string $group,
        public ProbeStatus $status,
        public int $durationMs,
        public string $detail = '',
        public ?string $userMessage = null,
        public array $payload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function ok(string $service, string $group, int $durationMs, string $detail = '', array $payload = []): self
    {
        return new self($service, $group, ProbeStatus::Ok, $durationMs, $detail, null, $payload);
    }

    public static function failed(string $service, string $group, int $durationMs, Throwable $e): self
    {
        return new self(
            service: $service,
            group: $group,
            status: $e instanceof ExternalServiceFailed && str_contains($e->getMessage(), 'no API key configured')
                // Not configured is not broken. Saying otherwise would make a
                // working two-provider chain look like a two-provider outage.
                ? ProbeStatus::Skipped
                : ProbeStatus::Failed,
            durationMs: $durationMs,
            detail: $e->getMessage(),
            userMessage: $e instanceof ExternalServiceFailed ? $e->userMessage() : null,
        );
    }

    public static function skipped(string $service, string $group, string $detail): self
    {
        return new self($service, $group, ProbeStatus::Skipped, 0, $detail);
    }

    public function isProblem(): bool
    {
        return $this->status->isProblem();
    }
}
