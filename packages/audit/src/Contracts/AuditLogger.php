<?php

declare(strict_types=1);

namespace Core\Audit\Contracts;

/**
 * The module's entire published surface: append an entry.
 *
 * Deliberately narrow — a sensitive operation is recorded, never queried back
 * through this interface. Consultation belongs to the Core Admin operator
 * console (Lot 6), which reads the table directly because it is the one
 * place allowed to (spec fonctionnelle §5.10: "consultable par les
 * opérateurs autorisés").
 */
interface AuditLogger
{
    public function record(AuditEntry $entry): void;
}
