<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class ResolveMoneyExceptionCommand
{
    /**
     * @param string $resolution `refunded_outside` | `recognized_as_revenue` | `no_action`
     */
    public function __construct(
        public string $exceptionId,
        public string $resolution,
        public string $operatorId,
        public ?string $reference,
        public ?string $note,
    ) {}
}
