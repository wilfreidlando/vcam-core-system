<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\ManualReceiptViews;
use Core\Billing\Contracts\ListManualReceipts;
use Core\Billing\Contracts\ListManualReceiptsCommand;
use Core\Billing\Contracts\ManualReceiptPage;
use Core\Billing\Contracts\ManualReceiptView;
use Core\Billing\Domain\Model\ManualReceipt;
use Core\Billing\Domain\Port\ManualReceiptRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0073 §4 — the offline receipts of a project, page after page.
 *
 * The scope is a **required** parameter (ADR-0069 §2): the listing
 * never crosses the projects the console account may see, and an
 * explicit project outside the scope yields an empty page rather than
 * another tenant's receipts.
 */
final readonly class ListManualReceiptsUseCase implements ListManualReceipts
{
    public function __construct(
        private ManualReceiptRepository $receipts,
    ) {}

    public function list(ListManualReceiptsCommand $command, AccessScope $scope): ManualReceiptPage
    {
        if (! $scope->allowsProject($command->projectId)) {
            return new ManualReceiptPage([]);
        }

        $page = $command->invoiceId !== null && $command->invoiceId !== ''
            ? $this->receipts->listByInvoice($command->invoiceId, $command->cursor, $command->limit)
            : $this->receipts->listByProject($command->projectId, $command->status, $command->cursor, $command->limit);

        $items = array_map(
            fn (ManualReceipt $receipt): ManualReceiptView => ManualReceiptViews::from($receipt),
            $page['items'],
        );

        return new ManualReceiptPage($items, $page['nextCursor']);
    }
}
