<?php

declare(strict_types=1);

use Core\Webhook\Domain\Model\WebhookSendOutcome;

it('classifies failure outcomes through HttpStatusClassifier (ADR-0055)', function (): void {
    // Success outcomes are never "permanent" — that flag only matters
    // on the failure path. Belt-and-braces assertion to lock that in.
    expect(WebhookSendOutcome::success(200, null)->isPermanentFailure)->toBeFalse();

    // 5xx, null, 408, 429 -> transient -> isPermanentFailure false.
    expect(WebhookSendOutcome::failure(500, 'oops')->isPermanentFailure)->toBeFalse()
        ->and(WebhookSendOutcome::failure(503, 'down')->isPermanentFailure)->toBeFalse()
        ->and(WebhookSendOutcome::failure(null, 'no response')->isPermanentFailure)->toBeFalse()
        ->and(WebhookSendOutcome::failure(408, 'timeout')->isPermanentFailure)->toBeFalse()
        ->and(WebhookSendOutcome::failure(429, 'slow down')->isPermanentFailure)->toBeFalse();

    // 4xx (except 408/429) -> permanent -> isPermanentFailure true.
    expect(WebhookSendOutcome::failure(400, 'bad')->isPermanentFailure)->toBeTrue()
        ->and(WebhookSendOutcome::failure(401, 'auth')->isPermanentFailure)->toBeTrue()
        ->and(WebhookSendOutcome::failure(403, 'forbidden')->isPermanentFailure)->toBeTrue()
        ->and(WebhookSendOutcome::failure(404, 'gone')->isPermanentFailure)->toBeTrue()
        ->and(WebhookSendOutcome::failure(405, 'wrong method')->isPermanentFailure)->toBeTrue()
        ->and(WebhookSendOutcome::failure(410, 'gone')->isPermanentFailure)->toBeTrue()
        ->and(WebhookSendOutcome::failure(422, 'unprocessable')->isPermanentFailure)->toBeTrue();
});

it('preserves the response body on failure so operators can diagnose the dead delivery', function (): void {
    $outcome = WebhookSendOutcome::failure(405, 'wrong method', 'Allow: GET');

    expect($outcome->responseBody)->toBe('Allow: GET')
        ->and($outcome->isPermanentFailure)->toBeTrue();
});
