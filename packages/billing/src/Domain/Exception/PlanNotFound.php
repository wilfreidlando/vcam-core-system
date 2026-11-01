<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0071 §1 — the plan a project asks for is not one of its own.
 *
 * The code is chosen by the project and never changes; it is the key the
 * project knows its catalogue by.
 */
final class PlanNotFound extends RuntimeException
{
    public static function becauseCode(string $code): self
    {
        return new self("No plan carries the code \"{$code}\" in this project.");
    }
}
