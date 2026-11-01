<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

/**
 * A console account never revokes itself: whoever does it locks themselves out and,
 * if they were the last administrator, the whole console. Another administrator does it.
 */
final class CannotRevokeOwnAccount extends RuntimeException
{
    public static function forOperator(string $operatorId): self
    {
        return new self(sprintf('Operator "%s" cannot revoke their own account: ask another administrator.', $operatorId));
    }
}
