<?php

declare(strict_types=1);

use Core\Webhook\Domain\Model\WebhookEndpoint;

/**
 * @param list<string> $events
 */
function makeEndpoint(
    array $events = ['payment.status_changed'],
    bool $active = true,
): WebhookEndpoint {
    return new WebhookEndpoint(
        id: 'endpoint-1',
        projectId: 'project-1',
        url: 'https://example.test/hooks',
        secret: 'whsec_test',
        subscribedEventTypes: $events,
        active: $active,
    );
}

it('rejects a non-http(s) URL', function (): void {
    new WebhookEndpoint(
        id: 'endpoint-1',
        projectId: 'project-1',
        url: 'ftp://example.test/hooks',
        secret: 'whsec_test',
        subscribedEventTypes: [],
        active: true,
    );
})->throws(InvalidArgumentException::class);

it('subscribes to an explicitly listed event type', function (): void {
    $endpoint = makeEndpoint(['payment.status_changed']);

    expect($endpoint->subscribesTo('payment.status_changed'))->toBeTrue()
        ->and($endpoint->subscribesTo('wallet.credited'))->toBeFalse();
});

it('subscribes to every event type with the * wildcard', function (): void {
    $endpoint = makeEndpoint(['*']);

    expect($endpoint->subscribesTo('payment.status_changed'))->toBeTrue()
        ->and($endpoint->subscribesTo('anything.else'))->toBeTrue();
});

it('subscribes to every event type when the list is empty', function (): void {
    $endpoint = makeEndpoint([]);

    expect($endpoint->subscribesTo('anything'))->toBeTrue();
});

it('never subscribes once deactivated', function (): void {
    $endpoint = makeEndpoint(['*'], active: true)->deactivated();

    expect($endpoint->active)->toBeFalse()
        ->and($endpoint->subscribesTo('payment.status_changed'))->toBeFalse();
});
