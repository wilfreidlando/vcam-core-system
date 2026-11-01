<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

/**
 * What the project knows about the customer — contact data masked: the
 * portal shows enough to recognise it, never the whole address.
 */
final readonly class PortalProfile
{
    public function __construct(
        public string $displayName,
        public string $reference,
        public ?string $maskedEmail,
        public ?string $maskedPhone,
        public string $locale,
        public string $memberSince,
    ) {}
}
