<?php

declare(strict_types=1);

use Core\Webhook\Application\UseCase\DeliverWebhookUseCase;
use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Contracts\WebhookDeliveryOutcomeStatus;
use Core\Webhook\Domain\Exception\WebhookDeliveryNotFound;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Domain\Model\WebhookRetryPolicy;
use Core\Webhook\Domain\Model\WebhookSendOutcome;
use Core\Webhook\Tests\Unit\Fakes\FakeWebhookSender;
use Core\Webhook\Tests\Unit\Fakes\FixedClock;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookDeliveryRepository;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookEndpointRepository;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;

/**
 * @return array{0: DeliverWebhookUseCase, 1: InMemoryWebhookDeliveryRepository, 2: FakeWebhookSender}
 */
function makeDeliverUseCase(WebhookRetryPolicy $retryPolicy = new WebhookRetryPolicy, bool $endpointActive = true): array
{
    $endpoints = new InMemoryWebhookEndpointRepository;
    $endpoints->create(new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.test/hooks', 'whsec_test', ['*'], $endpointActive));

    $deliveries = new InMemoryWebhookDeliveryRepository;
    $sender = new FakeWebhookSender;

    $useCase = new DeliverWebhookUseCase($deliveries, $endpoints, $sender, $retryPolicy, new FixedClock, new NullLogger);

    return [$useCase, $deliveries, $sender];
}

function seedPendingDelivery(InMemoryWebhookDeliveryRepository $deliveries, int $attempts = 0, ?DateTimeImmutable $nextRetryAt = null): void
{
    $deliveries->create(new WebhookDelivery(
        id: 'delivery-1',
        endpointId: 'endpoint-1',
        projectId: 'project-1',
        eventId: 'event-1',
        eventType: 'payment.status_changed',
        payload: ['status' => 'succeeded'],
        status: WebhookDeliveryStatus::Pending,
        attempts: $attempts,
        nextRetryAt: $nextRetryAt,
        lastAttemptAt: null,
        lastError: null,
        deliveredAt: null,
    ));
}

it('marks the delivery delivered on a successful send', function (): void {
    [$useCase, $deliveries] = makeDeliverUseCase();
    seedPendingDelivery($deliveries);

    $outcome = $useCase->deliver(new DeliverWebhookCommand('delivery-1'));

    $delivery = $deliveries->find('delivery-1');
    Assert::assertNotNull($delivery);
    expect($outcome->status)->toBe(WebhookDeliveryOutcomeStatus::Delivered)
        ->and($outcome->retryAfterSeconds)->toBeNull()
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Delivered);
});

it('schedules a retry on a transient failure, before the retry budget is exhausted', function (): void {
    [$useCase, $deliveries, $sender] = makeDeliverUseCase(new WebhookRetryPolicy(maxAttempts: 5, baseDelaySeconds: 30, multiplier: 3));
    seedPendingDelivery($deliveries);
    $sender->nextOutcome = WebhookSendOutcome::failure(503, 'endpoint unavailable');

    $outcome = $useCase->deliver(new DeliverWebhookCommand('delivery-1'));

    $delivery = $deliveries->find('delivery-1');
    Assert::assertNotNull($delivery);
    expect($outcome->status)->toBe(WebhookDeliveryOutcomeStatus::RetryScheduled)
        ->and($outcome->retryAfterSeconds)->toBe(30)
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->lastError)->toBe('endpoint unavailable');
});

it('moves the delivery to dead (DLQ) once the retry budget is exhausted', function (): void {
    [$useCase, $deliveries, $sender] = makeDeliverUseCase(new WebhookRetryPolicy(maxAttempts: 1));
    seedPendingDelivery($deliveries, attempts: 0);
    $sender->nextOutcome = WebhookSendOutcome::failure(500, 'still failing');

    $outcome = $useCase->deliver(new DeliverWebhookCommand('delivery-1'));

    $delivery = $deliveries->find('delivery-1');
    Assert::assertNotNull($delivery);
    expect($outcome->status)->toBe(WebhookDeliveryOutcomeStatus::Dead)
        ->and($outcome->retryAfterSeconds)->toBeNull()
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Dead);
});

it('moves the delivery to dead (DLQ) on a permanent 4xx, regardless of remaining retry budget (ADR-0055)', function (): void {
    // 8 attempts of budget — without ADR-0055, a 405 would burn the
    // whole budget (~2h) before going to dead. With the classification,
    // it dead-letters on the first attempt so an operator can act on it.
    [$useCase, $deliveries, $sender] = makeDeliverUseCase(new WebhookRetryPolicy(maxAttempts: 8));
    seedPendingDelivery($deliveries, attempts: 0);
    $sender->nextOutcome = WebhookSendOutcome::failure(405, 'wrong method');

    $outcome = $useCase->deliver(new DeliverWebhookCommand('delivery-1'));

    $delivery = $deliveries->find('delivery-1');
    Assert::assertNotNull($delivery);
    expect($outcome->status)->toBe(WebhookDeliveryOutcomeStatus::Dead)
        ->and($outcome->retryAfterSeconds)->toBeNull()
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Dead)
        ->and($delivery->lastResponseStatus)->toBe(405);
});

it('is a no-op for an already-delivered delivery and never sends again', function (): void {
    [$useCase, $deliveries, $sender] = makeDeliverUseCase();
    seedPendingDelivery($deliveries);
    $useCase->deliver(new DeliverWebhookCommand('delivery-1'));

    $outcome = $useCase->deliver(new DeliverWebhookCommand('delivery-1'));

    expect($outcome->status)->toBe(WebhookDeliveryOutcomeStatus::Delivered)
        ->and($sender->sent)->toHaveCount(1, 'a duplicate Horizon job execution must not re-send');
});

it('rejects an unknown delivery id', function (): void {
    [$useCase] = makeDeliverUseCase();

    $useCase->deliver(new DeliverWebhookCommand('unknown'));
})->throws(WebhookDeliveryNotFound::class);

it('cancels, without sending, a delivery whose endpoint was deactivated (audit 2026-10-03 §4.4)', function (): void {
    [$useCase, $deliveries, $sender] = makeDeliverUseCase(endpointActive: false);
    seedPendingDelivery($deliveries, attempts: 2);

    $outcome = $useCase->deliver(new DeliverWebhookCommand('delivery-1'));

    $delivery = $deliveries->find('delivery-1');
    Assert::assertNotNull($delivery);
    expect($outcome->status)->toBe(WebhookDeliveryOutcomeStatus::Canceled)
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Canceled)
        ->and($sender->sent)->toBe([]);
});

it('ignores a duplicate job that runs before the delivery is due (audit 2026-10-03 §4.3)', function (): void {
    [$useCase, $deliveries, $sender] = makeDeliverUseCase();
    // FixedClock is 2026-09-15T10:00:00Z; the next attempt is due in 10 minutes.
    seedPendingDelivery($deliveries, attempts: 1, nextRetryAt: new DateTimeImmutable('2026-09-15T10:10:00Z'));

    $outcome = $useCase->deliver(new DeliverWebhookCommand('delivery-1'));

    expect($outcome->status)->toBe(WebhookDeliveryOutcomeStatus::RetryScheduled)
        ->and($outcome->retryAfterSeconds)->toBeNull()
        ->and($sender->sent)->toBe([]);
});

it('sends a delivery whose retry is due', function (): void {
    [$useCase, $deliveries, $sender] = makeDeliverUseCase();
    seedPendingDelivery($deliveries, attempts: 1, nextRetryAt: new DateTimeImmutable('2026-09-15T10:00:00Z'));

    $outcome = $useCase->deliver(new DeliverWebhookCommand('delivery-1'));

    expect($outcome->status)->toBe(WebhookDeliveryOutcomeStatus::Delivered)
        ->and($sender->sent)->toHaveCount(1);
});
