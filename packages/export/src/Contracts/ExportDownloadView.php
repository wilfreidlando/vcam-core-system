<?php

declare(strict_types=1);

namespace Core\Export\Contracts;

final readonly class ExportDownloadView
{
    public function __construct(
        public string $exportId,
        public string $projectId,
        public string $filename,
    ) {}
}
