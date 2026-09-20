<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Exception;

use RuntimeException;

/**
 * A caller claimed a `projectId` that does not own the wallet it resolved
 * to — either an existing wallet found for `(partyId, currency)` belongs to
 * a different project (credit), or the wallet looked up by id belongs to a
 * different project than the one the caller stated (debit). Security
 * review finding: neither use case verified this before crediting/debiting
 * — a caller could move money into or out of another project's wallet by
 * supplying a mismatched `(projectId, partyId)`/`(projectId, walletId)`
 * pair. Every caller of `CreditWallet`/`DebitWallet` must now state the
 * project it believes it's acting on, and this is enforced.
 */
final class WalletProjectMismatch extends RuntimeException
{
    public static function forWallet(string $walletId, string $claimedProjectId): self
    {
        return new self("Wallet {$walletId} does not belong to project {$claimedProjectId}.");
    }
}
