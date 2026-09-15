<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

final readonly class NotificationPage
{
    /**
     * @param list<NotificationView> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
