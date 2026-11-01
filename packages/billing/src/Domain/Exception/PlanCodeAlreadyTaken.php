<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0071 §1 — the code of a plan is unique **per project**.
 *
 * The code is the key the catalogue is read by, and it stays stable for
 * the whole life of the plan (ADR-0071 §1): a second plan of the same
 * project may not carry it.
 */
final class PlanCodeAlreadyTaken extends RuntimeException
{
    public static function inProject(string $projectId): self
    {
        return new self("Another plan of project {$projectId} already carries this code.");
    }
}
