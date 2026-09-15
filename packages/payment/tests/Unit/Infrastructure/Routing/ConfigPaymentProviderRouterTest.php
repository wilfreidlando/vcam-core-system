<?php

declare(strict_types=1);

use Core\Payment\Contracts\NoProviderForChannelException;
use Core\Payment\Contracts\PaymentChannel;
use Core\Payment\Infrastructure\Routing\ConfigPaymentProviderRouter;

it('resolves the platform default provider for a channel', function (): void {
    $router = new ConfigPaymentProviderRouter(['mtn_momo' => 'my-coolpay']);

    $code = $router->resolveProvider(PaymentChannel::fromString('mtn_momo'), 'project-1');

    expect($code->toString())->toBe('my-coolpay');
});

it('prefers a project override over the platform default', function (): void {
    $router = new ConfigPaymentProviderRouter(
        defaults: ['mtn_momo' => 'my-coolpay'],
        projectOverrides: ['project-1' => ['mtn_momo' => 'mtn-direct']],
    );

    $forProjectOne = $router->resolveProvider(PaymentChannel::fromString('mtn_momo'), 'project-1');
    $forProjectTwo = $router->resolveProvider(PaymentChannel::fromString('mtn_momo'), 'project-2');

    expect($forProjectOne->toString())->toBe('mtn-direct')
        ->and($forProjectTwo->toString())->toBe('my-coolpay');
});

it('throws when no provider is configured for a channel', function (): void {
    $router = new ConfigPaymentProviderRouter(['mtn_momo' => 'my-coolpay']);

    $router->resolveProvider(PaymentChannel::fromString('orange_money'), 'project-1');
})->throws(NoProviderForChannelException::class);
