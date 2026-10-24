<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class PaymentInvariantReport
{
    /**
     * @param list<string> $violations one line per violation found in this run (new or already open)
     */
    public function __construct(
        public int $projectsChecked,
        public int $paymentsChecked,
        public int $exceptionsOpened,
        public array $violations,
    ) {}

    public function isClean(): bool
    {
        return $this->violations === [];
    }
}
