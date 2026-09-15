<?php

declare(strict_types=1);

use Core\Payment\Contracts\PaymentChannel;

it('accepts a lowercase, underscore-separated channel', function (string $value): void {
    expect((string) PaymentChannel::fromString($value))->toBe($value);
})->with(['mtn_momo', 'orange_money', 'card', 'a']);

it('rejects an uppercase channel', function (): void {
    PaymentChannel::fromString('MTN_Momo');
})->throws(InvalidArgumentException::class);

it('rejects a channel starting with a digit', function (): void {
    PaymentChannel::fromString('1mtn');
})->throws(InvalidArgumentException::class);

it('rejects a blank channel', function (): void {
    PaymentChannel::fromString('');
})->throws(InvalidArgumentException::class);

it('compares by value', function (): void {
    expect(PaymentChannel::fromString('mtn_momo')->equals(PaymentChannel::fromString('mtn_momo')))->toBeTrue()
        ->and(PaymentChannel::fromString('mtn_momo')->equals(PaymentChannel::fromString('orange_money')))->toBeFalse();
});
