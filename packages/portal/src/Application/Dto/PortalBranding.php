<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

/**
 * ADR-0075 §4 — what a page needs to know about the project it speaks for.
 */
final readonly class PortalBranding
{
    /**
     * @param list<string> $paymentChannels
     */
    public function __construct(
        public string $displayName,
        public ?string $logoUrl,
        public string $primaryColor,
        public ?string $supportUrl,
        public string $locale,
        public bool $allowSelfCancel,
        public array $paymentChannels,
        public bool $allowPlanChange = false,
    ) {}
}
