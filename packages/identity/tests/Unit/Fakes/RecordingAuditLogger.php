<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;

final class RecordingAuditLogger implements AuditLogger
{
    /** @var list<AuditEntry> */
    public array $entries = [];

    public function record(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}
