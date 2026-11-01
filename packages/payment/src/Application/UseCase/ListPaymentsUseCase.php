<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Application\Support\PaymentViews;
use Core\Payment\Contracts\ListPayments;
use Core\Payment\Contracts\ListPaymentsCommand;
use Core\Payment\Contracts\PaymentPage;
use Core\Payment\Contracts\PaymentView;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use Core\Support\Access\AccessScope;

final readonly class ListPaymentsUseCase implements ListPayments
{
    public function __construct(
        private PaymentRepository $payments,
    ) {}

    public function list(ListPaymentsCommand $command, AccessScope $scope): PaymentPage
    {
        $status = $command->status !== null ? PaymentStatus::tryFrom($command->status) : null;

        // ADR-0069 §2 — an explicit project outside the account's scope
        // yields nothing rather than another tenant's payments.
        if ($command->projectId !== null && ! $scope->allowsProject($command->projectId)) {
            return new PaymentPage([], 0, $command->page, $command->perPage);
        }

        $result = $this->payments->findByProject($command->projectId, $status, $command->page, $command->perPage, $scope->projectFilter());

        $items = array_map(
            static fn (Payment $payment): PaymentView => PaymentViews::from($payment),
            $result['items'],
        );

        return new PaymentPage($items, $result['total'], $command->page, $command->perPage);
    }
}
