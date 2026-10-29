<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

/**
 * ADR-0075 §4 — the settings of a project, as the API reads and writes them.
 */
final readonly class PortalSettingsData
{
    /**
     * @param list<string> $allowedReturnUrls
     * @param list<string> $paymentChannels
     */
    public function __construct(
        public string $displayName,
        public ?string $logoUrl,
        public string $primaryColor,
        public ?string $supportUrl,
        public string $locale,
        public array $allowedReturnUrls,
        public bool $allowSelfCancel,
        public bool $allowPlanChange,
        public array $paymentChannels,
    ) {}
}
