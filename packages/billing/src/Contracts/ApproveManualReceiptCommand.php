<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §4, ADR-0094 §13, ADR-0095 — the validation of an offline
 * collection.
 *
 * The approver is the **console account** that decides. ADR-0095
 * withdrew the four eyes: it may be the account that recorded the
 * receipt.
 */
final readonly class ApproveManualReceiptCommand
{
    public function __construct(
        public string $projectId,
        public string $receiptId,
        /** The console account that approves (or refuses) the receipt. */
        public string $approver,
        /** `false` means the second account refuses the receipt. */
        public bool $approved = true,
        public string $actor = '',
        public ?string $correlationId = null,
    ) {}
}
