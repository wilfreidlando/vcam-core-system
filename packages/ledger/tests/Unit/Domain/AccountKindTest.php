<?php

declare(strict_types=1);

use Core\Ledger\Domain\Model\AccountKind;

it('accepts a lowercase, underscore-separated kind', function (string $value): void {
    expect((string) AccountKind::fromString($value))->toBe($value);
})->with(['wallet', 'clearing', 'platform_fee', 'a']);

it('rejects an uppercase kind', function (): void {
    AccountKind::fromString('Wallet');
})->throws(InvalidArgumentException::class);

it('rejects a kind starting with a digit', function (): void {
    AccountKind::fromString('1wallet');
})->throws(InvalidArgumentException::class);

it('rejects a kind with a hyphen', function (): void {
    AccountKind::fromString('platform-fee');
})->throws(InvalidArgumentException::class);

it('rejects a blank kind', function (): void {
    AccountKind::fromString('');
})->throws(InvalidArgumentException::class);

it('compares by value', function (): void {
    expect(AccountKind::fromString('wallet')->equals(AccountKind::fromString('wallet')))->toBeTrue()
        ->and(AccountKind::fromString('wallet')->equals(AccountKind::fromString('clearing')))->toBeFalse();
});
