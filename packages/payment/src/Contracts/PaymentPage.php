<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class PaymentPage
{
    /**
     * @param list<PaymentView> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
