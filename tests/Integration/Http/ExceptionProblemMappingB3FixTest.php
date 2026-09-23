<?php

declare(strict_types=1);

use App\Http\ExceptionProblemMapping;
use Core\Payment\Contracts\NoProviderForChannelException;
use Core\Payment\Contracts\PaymentChannel;
use Core\Payment\Domain\Exception\PaymentNotFound;
use Core\Payment\Domain\Exception\ProviderApplicationNotFound;
use Core\Wallet\Domain\Exception\WalletNotFound;
use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\Assert;

/**
 * Locks the B3 fix from the doc-completeness commit: any domain
 * exception listed in `ExceptionProblemMapping::MAP` must surface as
 * a clean problem+json response, not a 500 with a stack trace.
 *
 * `ProviderApplicationNotFound` was the regression we hit during the
 * audit — a fresh Project (per-Project MyCoolPay credentials, ADR-0051)
 * calling `POST /v1/payments` was getting a 500 with the full stack
 * because the exception was never wired into the mapping table. The
 * test below pins the mapping entry so a future refactor that drops
 * it fails loudly.
 */
it('surfaces ProviderApplicationNotFound as a clean 422 problem+json (no 500 with stack trace)', function (): void {
    $exception = ProviderApplicationNotFound::forProject(
        '01a00000-0000-0000-0000-000000000001',
        'my-coolpay',
    );

    $response = ExceptionProblemMapping::toResponse($exception);

    Assert::assertInstanceOf(JsonResponse::class, $response);

    Assert::assertSame(422, $response->getStatusCode());
    Assert::assertSame('application/problem+json', $response->headers->get('Content-Type'));

    $content = $response->getContent();
    if ($content === false) {
        $content = '{}';
    }
    /** @var array{type: string, title: string, status: int, detail: string} $body */
    $body = json_decode($content, true);
    Assert::assertSame('https://errors.core-system.internal/no-provider-application', $body['type']);
    Assert::assertSame('No provider application is registered for this project', $body['title']);
    Assert::assertSame(422, $body['status']);
    Assert::assertStringContainsString('my-coolpay', $body['detail']);
    Assert::assertStringContainsString('01a00000-0000-0000-0000-000000000001', $body['detail']);
});

it('still surfaces every other mapped exception unchanged (regression guard)', function (): void {
    // Sanity check: the existing entries must keep working after
    // adding the new one.
    $walletNotFound = WalletNotFound::withId('test-id');
    $paymentNotFound = PaymentNotFound::withId('test-id');
    $noProvider = NoProviderForChannelException::forChannel(
        PaymentChannel::fromString('mtn_momo'),
        '01a00000-0000-0000-0000-000000000001',
    );

    $cases = [
        [$walletNotFound, 'wallet-not-found', 404],
        [$paymentNotFound, 'payment-not-found', 404],
        [$noProvider, 'no-provider-for-channel', 422],
    ];

    foreach ($cases as [$exception, $expectedType, $expectedStatus]) {
        $response = ExceptionProblemMapping::toResponse($exception);

        Assert::assertInstanceOf(JsonResponse::class, $response, "mapping missing for {$expectedType}");

        $content = $response->getContent();
        if ($content === false) {
            $content = '{}';
        }
        /** @var array{type: string} $body */
        $body = json_decode($content, true);
        Assert::assertSame("https://errors.core-system.internal/{$expectedType}", $body['type']);
        Assert::assertSame($expectedStatus, $response->getStatusCode());
    }
});
