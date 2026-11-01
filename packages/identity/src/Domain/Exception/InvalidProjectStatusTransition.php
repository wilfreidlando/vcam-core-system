<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

/** ADR-0069 §3 — a transition the lifecycle does not allow. */
final class InvalidProjectStatusTransition extends RuntimeException
{
    public static function fromTo(string $from, string $to): self
    {
        return new self(sprintf(
            'Un projet « %s » ne peut pas passer à « %s » (transitions autorisées : %s).',
            $from,
            $to,
            $from === 'archived' ? 'aucune, l\'archivage est final' : 'active ⇄ suspended, active|suspended → archived',
        ));
    }
}
