<?php

declare(strict_types=1);

use Core\Support\Integration\Signing\HmacSha256RequestSigner;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Infrastructure\Delivery\HttpWebhookSender;
use Core\Webhook\Tests\Unit\Fakes\FixedClock;
use Core\Webhook\Tests\Unit\Fakes\RecordingHttpTransport;

function deliverableEvent(): WebhookDelivery
{
    return new WebhookDelivery(
        id: 'delivery-1',
        endpointId: 'endpoint-1',
        projectId: 'project-1',
        eventId: 'event-1',
        eventType: 'payment.status_changed',
        payload: ['status' => 'succeeded', 'transaction_ref' => 'tx-1'],
        status: WebhookDeliveryStatus::Pending,
        attempts: 0,
        nextRetryAt: null,
        lastAttemptAt: null,
        lastError: null,
        deliveredAt: null,
    );
}

it('wraps the delivered body in a self-describing envelope, not the raw payload alone', function (): void {
    // Review finding: an endpoint subscribed to several event types on the
    // same URL had no reliable way to tell deliveries apart from the body
    // alone — only the X-Event-Type header did, and headers are not
    // always convenient (or even preserved) on every receiving stack.
    $transport = new RecordingHttpTransport;
    $sender = new HttpWebhookSender($transport, new HmacSha256RequestSigner, new FixedClock);
    $endpoint = new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.com/hooks', 'whsec_test', ['*'], true);

    $sender->send($endpoint, deliverableEvent());

    expect($transport->sent)->toHaveCount(1);
    $body = $transport->sent[0]['body'];
    assert(is_string($body));

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($body, true);
    expect($decoded)->toBe([
        'id' => 'event-1',
        'type' => 'payment.status_changed',
        'data' => ['status' => 'succeeded', 'transaction_ref' => 'tx-1'],
    ]);
});

it('still carries X-Event-Id and X-Event-Type headers alongside the envelope', function (): void {
    $transport = new RecordingHttpTransport;
    $sender = new HttpWebhookSender($transport, new HmacSha256RequestSigner, new FixedClock);
    $endpoint = new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.com/hooks', 'whsec_test', ['*'], true);

    $sender->send($endpoint, deliverableEvent());

    $headers = $transport->sent[0]['headers'];
    expect($headers['X-Event-Id'])->toBe('event-1')
        ->and($headers['X-Event-Type'])->toBe('payment.status_changed');
});

it('signs the envelope body, not the raw payload — a receiver verifies against what it actually received', function (): void {
    $transport = new RecordingHttpTransport;
    $sender = new HttpWebhookSender($transport, new HmacSha256RequestSigner, new FixedClock);
    $endpoint = new WebhookEndpoint('endpoint-1', 'project-1', 'https://example.com/hooks', 'whsec_test', ['*'], true);

    $sender->send($endpoint, deliverableEvent());

    $headers = $transport->sent[0]['headers'];
    $body = $transport->sent[0]['body'];
    assert(is_string($body));
    $expectedSignature = hash_hmac('sha256', $headers['X-Timestamp'].'.'.$body, 'whsec_test');

    expect($headers['X-Signature'])->toBe($expectedSignature);
});
