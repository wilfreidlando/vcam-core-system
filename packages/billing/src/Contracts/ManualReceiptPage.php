<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

final readonly class ManualReceiptPage
{
    /**
     * @param list<ManualReceiptView> $items
     * @param string|null $nextCursor pass it back to read the next page; `null` = no more
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
    ) {}
}
