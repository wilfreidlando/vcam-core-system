<?php

declare(strict_types=1);

namespace Core\Portal\Contracts;

/**
 * ADR-0075 §4 — the look and the permissions of a project's portal, as the
 * console reads and writes them.
 */
final readonly class PortalSettingsView
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
        public bool $directLoginEnabled = false,
        public ?string $directLoginSession = null,
    ) {}
}
