<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0075 §2 — the contact to look a customer up by.
 */
final readonly class FindCustomerByContactCommand
{
    public const KIND_PHONE = 'phone';

    public const KIND_EMAIL = 'email';

    /**
     * @param string $kind `phone` or `email`
     * @param string $value the contact as typed; it is normalised like the stored one
     */
    public function __construct(
        public string $projectId,
        public string $kind,
        public string $value,
    ) {}
}
