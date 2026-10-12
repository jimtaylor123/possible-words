<?php

namespace App\Services;

/**
 * The safe, structured result of one RDAP request. Raw provider responses and
 * exception details intentionally never cross this boundary.
 */
readonly class DomainAvailabilityOutcome
{
    public function __construct(
        public string $status,
        public string $provider,
        public ?string $failureKind = null,
        public ?string $failureMessage = null,
        public ?int $httpStatus = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    public function isConclusive(): bool
    {
        return $this->failureKind === null;
    }

    /**
     * @return array{kind: string, message: string, http_status: ?int, occurred_at: string}|null
     */
    public function failureDiagnostic(): ?array
    {
        if ($this->failureKind === null || $this->failureMessage === null) {
            return null;
        }

        return [
            'kind' => $this->failureKind,
            'message' => $this->failureMessage,
            'http_status' => $this->httpStatus,
            'occurred_at' => now()->toISOString(),
        ];
    }
}
