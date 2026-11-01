<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * Read model of a money exception (ADR-0077 §3). Plain scalars only: the
 * enums stay out of `Contracts/` (G3c), their values travel as strings.
 */
final readonly class MoneyExceptionView
{
    /**
     * @param array<string, mixed> $details
     * @param list<string> $allowedResolutions resolution values a person may choose now
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $kind,
        public string $kindLabel,
        public string $severity,
        public int $amountMinor,
        public string $currency,
        public int $bookedAmountMinor,
        public ?string $paymentId,
        public ?string $subjectRef,
        public string $summary,
        public array $details,
        public string $status,
        public ?string $resolution,
        public ?string $resolutionLabel,
        public ?string $resolutionReference,
        public ?string $resolutionNote,
        public ?string $resolvedBy,
        public ?string $approvedBy,
        public string $createdAt,
        public ?string $resolvedAt,
        public array $allowedResolutions,
    ) {}
}
