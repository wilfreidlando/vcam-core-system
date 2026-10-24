<?php

declare(strict_types=1);

namespace Core\Audit\Contracts;

final readonly class AuditVerificationResult
{
    /**
     * @param list<string> $brokenDays days whose recomputed digest or chain link does not match (Y-m-d)
     */
    public function __construct(
        public int $daysChecked,
        public array $brokenDays,
    ) {}

    public function isIntact(): bool
    {
        return $this->brokenDays === [];
    }
}
