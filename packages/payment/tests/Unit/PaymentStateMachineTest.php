<?php

declare(strict_types=1);

use Core\Payment\PaymentStatus;

/*
 * ADR-0061 — intermediate vs final, and the only accepted transitions.
 */
it('distinguishes intermediate from final statuses', function (): void {
    expect(PaymentStatus::Pending->isFinal())->toBeFalse()
        ->and(PaymentStatus::Processing->isFinal())->toBeFalse()
        ->and(PaymentStatus::Succeeded->isFinal())->toBeTrue()
        ->and(PaymentStatus::Failed->isFinal())->toBeTrue()
        ->and(PaymentStatus::Expired->isFinal())->toBeTrue()
        ->and(PaymentStatus::Canceled->isFinal())->toBeTrue()
        ->and(PaymentStatus::Refunded->isFinal())->toBeTrue();
});

it('only lets a payment move forward', function (): void {
    expect(PaymentStatus::Pending->canTransitionTo(PaymentStatus::Succeeded))->toBeTrue()
        ->and(PaymentStatus::Processing->canTransitionTo(PaymentStatus::Failed))->toBeTrue()
        ->and(PaymentStatus::Succeeded->canTransitionTo(PaymentStatus::Refunded))->toBeTrue()
        // a late or replayed callback can never move a settled payment backwards
        ->and(PaymentStatus::Succeeded->canTransitionTo(PaymentStatus::Pending))->toBeFalse()
        ->and(PaymentStatus::Succeeded->canTransitionTo(PaymentStatus::Failed))->toBeFalse()
        ->and(PaymentStatus::Processing->canTransitionTo(PaymentStatus::Pending))->toBeFalse()
        ->and(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Succeeded))->toBeFalse()
        ->and(PaymentStatus::Pending->canTransitionTo(PaymentStatus::Pending))->toBeFalse();
});
