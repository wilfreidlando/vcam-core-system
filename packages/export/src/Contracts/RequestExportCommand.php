<?php

declare(strict_types=1);

namespace Core\Export\Contracts;

final readonly class RequestExportCommand
{
    /**
     * @param string $requestedBy who asks: `api_key:<id>` or `operator:<id>` — written to the audit log
     */
    public function __construct(
        public string $projectId,
        public string $organizationId,
        public string $requestedBy,
        public ?string $correlationId = null,
    ) {}
}
