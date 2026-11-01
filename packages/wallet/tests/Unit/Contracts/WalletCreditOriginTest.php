<?php

declare(strict_types=1);

use Core\Wallet\Contracts\WalletCreditOrigin;
use Core\Wallet\Contracts\WalletCreditSource;
use Core\Wallet\Domain\Model\SystemAccount;

it('names the provider as the account reference of a clearing origin', function (): void {
    $origin = new WalletCreditOrigin(WalletCreditSource::Clearing, ' my-coolpay ');

    expect($origin->accountReference())->toBe('my-coolpay')
        ->and($origin->providerCode)->toBe('my-coolpay')
        ->and($origin->source)->toBe(WalletCreditSource::Clearing);
});

it('uses the project-level reference for an exception-pool origin', function (): void {
    $origin = new WalletCreditOrigin(WalletCreditSource::PaymentExceptions);

    expect($origin->accountReference())->toBe('')
        ->and($origin->providerCode)->toBeNull()
        ->and($origin->source)->toBe(WalletCreditSource::PaymentExceptions);
});

it('refuses a clearing origin without a provider: it would fall back on the historical account', function (): void {
    new WalletCreditOrigin(WalletCreditSource::Clearing);
})->throws(InvalidArgumentException::class);

it('refuses a clearing origin with a blank provider', function (): void {
    new WalletCreditOrigin(WalletCreditSource::Clearing, '   ');
})->throws(InvalidArgumentException::class);

it('refuses an exception-pool origin carrying a provider: no provider holds that money', function (): void {
    new WalletCreditOrigin(WalletCreditSource::PaymentExceptions, 'my-coolpay');
})->throws(InvalidArgumentException::class);

it('keeps the clearing source aligned with the wallet own system account', function (): void {
    expect(WalletCreditSource::Clearing->value)->toBe(SystemAccount::CLEARING);
});
