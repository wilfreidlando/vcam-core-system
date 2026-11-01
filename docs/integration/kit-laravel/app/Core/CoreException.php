<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * The Core refused, or could not be reached. One exception for every call, so
 * your code asks questions instead of parsing statuses:
 *
 *   try { $payments->start(...); }
 *   catch (CoreException $e) {
 *       if ($e->isTransient()) { retry later with the SAME idempotency key; }
 *       elseif ($e->is('insufficient-funds')) { ... }
 *       else { show $e->detail }
 *   }
 */
final class CoreException extends RuntimeException
{
    /**
     * @param array<string, mixed> $body the decoded problem+json (or [])
     */
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        public readonly string $detail,
        public readonly array $body = [],
        public readonly ?int $retryAfter = null,
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct("Core {$status} {$type}: {$detail}");
    }

    /** Status 0: the Core could not be reached at all. */
    public static function unreachable(string $reason): self
    {
        return new self(0, 'unreachable', $reason);
    }

    /**
     * Worth retrying later **with the same Idempotency-Key**: the Core did
     * not answer, was busy (429, 503, 504) or timed out (408). A provider
     * refusal (502 `provider-error`) and every other 4xx are final.
     */
    public function isTransient(): bool
    {
        return in_array($this->status, [0, 408, 429, 503, 504], true);
    }

    /** `is('insufficient-funds')`, `is('validation-failed')`… the last segment of the problem type. */
    public function is(string $type): bool
    {
        return $this->type === $type;
    }
}
