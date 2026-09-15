<?php

declare(strict_types=1);

use Core\Notification\NotificationStatus;

it('is terminal only for read and failed', function (): void {
    expect(NotificationStatus::Queued->isTerminal())->toBeFalse()
        ->and(NotificationStatus::Sent->isTerminal())->toBeFalse()
        ->and(NotificationStatus::Delivered->isTerminal())->toBeFalse()
        ->and(NotificationStatus::Read->isTerminal())->toBeTrue()
        ->and(NotificationStatus::Failed->isTerminal())->toBeTrue();
});

it('allows queued to move to sent or failed only', function (): void {
    expect(NotificationStatus::Queued->canTransitionTo(NotificationStatus::Sent))->toBeTrue()
        ->and(NotificationStatus::Queued->canTransitionTo(NotificationStatus::Failed))->toBeTrue()
        ->and(NotificationStatus::Queued->canTransitionTo(NotificationStatus::Delivered))->toBeFalse()
        ->and(NotificationStatus::Queued->canTransitionTo(NotificationStatus::Read))->toBeFalse();
});

it('allows sent to move to delivered or failed only', function (): void {
    expect(NotificationStatus::Sent->canTransitionTo(NotificationStatus::Delivered))->toBeTrue()
        ->and(NotificationStatus::Sent->canTransitionTo(NotificationStatus::Failed))->toBeTrue()
        ->and(NotificationStatus::Sent->canTransitionTo(NotificationStatus::Read))->toBeFalse()
        ->and(NotificationStatus::Sent->canTransitionTo(NotificationStatus::Queued))->toBeFalse();
});

it('allows delivered to move to read only', function (): void {
    expect(NotificationStatus::Delivered->canTransitionTo(NotificationStatus::Read))->toBeTrue()
        ->and(NotificationStatus::Delivered->canTransitionTo(NotificationStatus::Failed))->toBeFalse()
        ->and(NotificationStatus::Delivered->canTransitionTo(NotificationStatus::Sent))->toBeFalse();
});

it('never allows a transition out of read or failed', function (): void {
    foreach (NotificationStatus::cases() as $next) {
        expect(NotificationStatus::Read->canTransitionTo($next))->toBeFalse()
            ->and(NotificationStatus::Failed->canTransitionTo($next))->toBeFalse();
    }
});
