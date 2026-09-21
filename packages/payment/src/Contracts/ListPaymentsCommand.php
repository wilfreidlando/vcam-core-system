<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * `status`, if given, is the raw canonical status string (e.g.
 * `"succeeded"`). A `Core\Payment\PaymentStatus` value on this DTO would
 * make it unusable from outside the module (G3c — `PaymentStatus` is
 * top-level, not under `Contracts/`); the use case converts it via
 * `PaymentStatus::tryFrom()`, treating an unrecognised value as "no filter"
 * rather than an error.
 *
 * `projectId` is nullable — omitting it lists across every Project. This
 * port has no Project/API-key caller (only the trusted operator-
 * authenticated admin plane reaches it), so a null value is not a
 * privacy leak, just the admin console browsing payments platform-wide
 * instead of one Project at a time.
 */
final readonly class ListPaymentsCommand
{
    public function __construct(
        public ?string $projectId = null,
        public ?string $status = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
