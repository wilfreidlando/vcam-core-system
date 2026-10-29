<?php

declare(strict_types=1);

namespace Core\Export\Domain\Model;

enum ExportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}
