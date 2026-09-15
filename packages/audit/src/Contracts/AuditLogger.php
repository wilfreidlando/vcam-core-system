<?php

declare(strict_types=1);

namespace Core\Audit\Contracts;

/**
 * Append an entry. Deliberately narrow — a sensitive operation is recorded,
 * never queried back through this interface. Consultation belongs to the
 * Core Admin operator console (Lot 6), via the separate `AuditQuery` port
 * (ADR-0044) — G3c makes no exception for `admin`, even though it composes
 * every module (ADR-0027: "Admin compose, il n'interroge pas directement").
 */
interface AuditLogger
{
    public function record(AuditEntry $entry): void;
}
