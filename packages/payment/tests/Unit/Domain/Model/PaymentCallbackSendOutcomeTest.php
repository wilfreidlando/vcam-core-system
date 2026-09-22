<?php

declare(strict_types=1);

use Core\Payment\Domain\Model\PaymentCallbackSendOutcome;

it('classifies failure outcomes through HttpStatusClassifier (ADR-0055)', function (): void {
    // Success and "no callback" outcomes are never permanent.
    expect(PaymentCallbackSendOutcome::success(200)->isPermanentFailure)->toBeFalse()
        ->and(PaymentCallbackSendOutcome::noCallbackConfigured()->isPermanentFailure)->toBeFalse();

    // Transient failures: null, 5xx, 408, 429.
    expect(PaymentCallbackSendOutcome::failure(null, 'no response')->isPermanentFailure)->toBeFalse()
        ->and(PaymentCallbackSendOutcome::failure(500, 'oops')->isPermanentFailure)->toBeFalse()
        ->and(PaymentCallbackSendOutcome::failure(503, 'down')->isPermanentFailure)->toBeFalse()
        ->and(PaymentCallbackSendOutcome::failure(408, 'timeout')->isPermanentFailure)->toBeFalse()
        ->and(PaymentCallbackSendOutcome::failure(429, 'slow down')->isPermanentFailure)->toBeFalse();

    // Permanent failures: any 4xx other than 408/429. 405 is the
    // documented production case (callback URL that does not accept
    // POST) — locking it in here so a future refactor that loses the
    // classification fails this test loudly.
    expect(PaymentCallbackSendOutcome::failure(400, 'bad')->isPermanentFailure)->toBeTrue()
        ->and(PaymentCallbackSendOutcome::failure(404, 'gone')->isPermanentFailure)->toBeTrue()
        ->and(PaymentCallbackSendOutcome::failure(405, 'wrong method')->isPermanentFailure)->toBeTrue()
        ->and(PaymentCallbackSendOutcome::failure(410, 'gone')->isPermanentFailure)->toBeTrue()
        ->and(PaymentCallbackSendOutcome::failure(422, 'unprocessable')->isPermanentFailure)->toBeTrue();
});
