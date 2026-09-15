<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Exception;

use RuntimeException;

/**
 * The `(project_id, kind, reference, currency)` unique constraint rejected
 * an insert — two concurrent resolve-or-create calls raced. Not an error
 * from the caller's point of view: the use case catches this and re-reads
 * the account the winner created.
 */
final class AccountAlreadyExists extends RuntimeException {}
