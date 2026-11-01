<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class PaymentCursorPage
{
    /**
     * @param list<PaymentView> $items oldest first
     * @param string|null $nextCursor pass it back to read the next page; `null` = no more
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
    ) {}
}
