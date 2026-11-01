<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Usage;

use Core\Billing\Domain\Model\UsageAdmission;
use RuntimeException;

/**
 * Internal signal: unwinds the savepoint of a refused admission so that
 * the idempotency key, the counter and the journal are all rolled back
 * together (ADR-0074 §2). Never leaves the store.
 */
final class UsageRefusal extends RuntimeException
{
    public function __construct(public readonly UsageAdmission $admission)
    {
        parent::__construct('usage refused');
    }
}
