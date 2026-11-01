<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * @template T
 */
final readonly class UsagePage
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
    ) {}
}
