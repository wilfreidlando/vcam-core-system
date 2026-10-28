<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0094 §13 — the receipt can no longer be validated: it is not
 * waiting for a validation any more.
 *
 * ADR-0095 withdrew the four eyes (ADR-0073 §4): **one** console
 * account is enough to validate an offline receipt, the author
 * included. What remains is the deliberate, audited act of validation
 * — and this refusal says that the receipt has already been through it.
 */
final class ManualReceiptApprovalRefused extends RuntimeException
{
    public static function becauseNotPending(string $receiptId, string $status): self
    {
        return new self("The offline receipt \"{$receiptId}\" is in state \"{$status}\" and cannot be validated any more.");
    }
}
