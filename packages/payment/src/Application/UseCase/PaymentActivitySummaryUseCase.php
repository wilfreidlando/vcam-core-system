<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Contracts\PaymentActivitySummary;
use Core\Payment\Contracts\PaymentActivitySummaryView;
use Core\Payment\Contracts\PaymentActivitySummaryWindow;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;

final readonly class PaymentActivitySummaryUseCase implements PaymentActivitySummary
{
    public function __construct(
        private PaymentRepository $payments,
        private Clock $clock,
    ) {}

    public function summarize(PaymentActivitySummaryWindow $window, AccessScope $scope): PaymentActivitySummaryView
    {
        $since = $this->clock->now()->modify(sprintf('-%d hours', $window->sinceHours));

        // ADR-0069 §2 — the scope filters the aggregation in the query:
        // the payments of another tenant are never counted.
        $counts = $this->payments->countRecentByStatus($since, $scope->projectFilter());

        return new PaymentActivitySummaryView($counts['total'], $counts['succeeded']);
    }
}
