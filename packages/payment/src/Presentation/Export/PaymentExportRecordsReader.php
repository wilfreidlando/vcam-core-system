<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Export;

use Core\Payment\Contracts\ListPaymentsByCursor;
use Core\Payment\Contracts\ListPaymentsByCursorCommand;
use Core\Payment\Contracts\PaymentExportRecords;
use Core\Payment\Presentation\Http\Presenters\PaymentJson;
use Core\Support\Access\AccessScope;

final readonly class PaymentExportRecordsReader implements PaymentExportRecords
{
    public function __construct(private ListPaymentsByCursor $payments) {}

    public function page(string $projectId, ?string $cursor, int $limit): array
    {
        $page = $this->payments->list(
            new ListPaymentsByCursorCommand(projectId: $projectId, cursor: $cursor, limit: $limit),
            AccessScope::forProject($projectId),
        );

        return ['rows' => array_map(PaymentJson::status(...), $page->items), 'next_cursor' => $page->nextCursor];
    }
}
