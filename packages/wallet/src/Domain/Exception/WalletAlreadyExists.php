<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Exception;

use RuntimeException;

/**
 * The `(party_id, currency)` unique constraint rejected an insert — two
 * concurrent lazy-creation calls raced. The use case catches this and
 * re-reads the wallet the winner created.
 */
final class WalletAlreadyExists extends RuntimeException {}
