<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Application\Support\PaymentViews;
use Core\Payment\Contracts\ListPaymentsByCursor;
use Core\Payment\Contracts\ListPaymentsByCursorCommand;
use Core\Payment\Contracts\PaymentCursorPage;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use Core\Support\Access\AccessScope;

final readonly class ListPaymentsByCursorUseCase implements ListPaymentsByCursor
{
    private const MAX_LIMIT = 100;

    public function __construct(
        private PaymentRepository $payments,
    ) {}

    public function list(ListPaymentsByCursorCommand $command, AccessScope $scope): PaymentCursorPage
    {
        // ADR-0069 §2 — a project outside the scope yields nothing rather
        // than another tenant's payments.
        if (! $scope->allowsProject($command->projectId)) {
            return new PaymentCursorPage([]);
        }

        $page = $this->payments->listByCursor(
            $command->projectId,
            $command->status !== null ? PaymentStatus::tryFrom($command->status) : null,
            $command->createdFrom,
            $command->createdTo,
            $command->cursor,
            max(1, min(self::MAX_LIMIT, $command->limit)),
        );

        return new PaymentCursorPage(
            array_map(static fn (Payment $payment) => PaymentViews::from($payment), $page['items']),
            $page['nextCursor'],
        );
    }
}
