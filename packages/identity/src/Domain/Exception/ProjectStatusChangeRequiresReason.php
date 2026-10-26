<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

/** ADR-0069 §3 — every transition is audited with a reason. */
final class ProjectStatusChangeRequiresReason extends RuntimeException
{
    public static function forReason(): self
    {
        return new self('Le motif du changement de statut est obligatoire (il est journalisé).');
    }
}
