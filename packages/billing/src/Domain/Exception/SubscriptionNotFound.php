<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0072 §2 — the contract a project asks for is not one of its own.
 *
 * A contract of another project is **not found**, never told apart from
 * one that does not exist (G15, ADR-0070 §Sécurité).
 */
final class SubscriptionNotFound extends RuntimeException
{
    public static function becauseId(string $id): self
    {
        return new self("No subscription carries the id \"{$id}\" in this project.");
    }
}
