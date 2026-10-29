<?php

declare(strict_types=1);

namespace Core\Portal\Contracts;

final readonly class SavePortalSettingsCommand
{
    /**
     * @param list<string> $allowedReturnUrls
     * @param list<string> $paymentChannels
     * @param string $actor "operator:<uuid>" — who changed it (audited)
     */
    public function __construct(
        public string $projectId,
        public string $displayName,
        public ?string $logoUrl,
        public string $primaryColor,
        public ?string $supportUrl,
        public string $locale,
        public array $allowedReturnUrls,
        public bool $allowSelfCancel,
        public bool $allowPlanChange,
        public array $paymentChannels,
        public string $actor,
        public ?string $correlationId = null,
        public bool $directLoginEnabled = false,
        public ?string $directLoginSession = null,
    ) {}
}
