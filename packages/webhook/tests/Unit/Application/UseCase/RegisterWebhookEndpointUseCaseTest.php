<?php

declare(strict_types=1);

use Core\Webhook\Application\UseCase\RegisterWebhookEndpointUseCase;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Core\Webhook\Tests\Unit\Fakes\FixedWebhookSecretGenerator;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookEndpointRepository;
use Core\Webhook\Tests\Unit\Fakes\SequentialIdentifier;
use PHPUnit\Framework\Assert;

it('registers an endpoint and returns the plaintext secret once', function (): void {
    $endpoints = new InMemoryWebhookEndpointRepository;
    $useCase = new RegisterWebhookEndpointUseCase($endpoints, new FixedWebhookSecretGenerator('whsec_abc'), new SequentialIdentifier);

    $view = $useCase->register(new RegisterWebhookEndpointCommand('project-1', 'https://example.test/hooks', ['payment.status_changed']));

    expect($view->id)->toBe('id-1')
        ->and($view->url)->toBe('https://example.test/hooks')
        ->and($view->events)->toBe(['payment.status_changed'])
        ->and($view->secret)->toBe('whsec_abc');

    $stored = $endpoints->find('id-1');
    Assert::assertNotNull($stored);
    expect($stored->secret)->toBe('whsec_abc')
        ->and($stored->active)->toBeTrue();
});
