<?php

declare(strict_types=1);

use App\OpenApi\RequiredScopesExtension;

/**
 * Locks the B4 fix from the doc-completeness commit: every public
 * operation must carry an `x-required-scopes` extension listing the
 * API-key scopes a caller must hold. Without this, a third-party
 * integrator has to read each controller's source to know what
 * scopes are needed — which means the doc is unusable for the most
 * common pre-flight question ("can my key call this?").
 *
 * The extension is intentionally dumb: it regex-scrapes
 * `$project->hasScope('xxx:yyy')` literals out of the controller's
 * source file (including parents, for `AbstractStartPaymentController`).
 * These tests pin the four scopes the production controllers currently
 * require, by reading the actual source files — same path the
 * extension takes at runtime, so any controller refactor that
 * silently drops the scope check is caught here.
 */
it('scrapes payments:write from AbstractStartPaymentController (parent of the three Start* classes)', function (): void {
    $scopes = RequiredScopesExtension::extractScopesFromController(
        Core\Payment\Presentation\Http\Controllers\StartPaymentController::class,
    );

    expect($scopes)->toContain('payments:write');
});

it('scrapes the right scope on each notification controller', function (): void {
    expect(RequiredScopesExtension::extractScopesFromController(
        Core\Notification\Presentation\Http\Controllers\SendTextNotificationController::class,
    ))->toContain('notifications:write');

    expect(RequiredScopesExtension::extractScopesFromController(
        Core\Notification\Presentation\Http\Controllers\SendImageNotificationController::class,
    ))->toContain('notifications:write');

    expect(RequiredScopesExtension::extractScopesFromController(
        Core\Notification\Presentation\Http\Controllers\SendFileNotificationController::class,
    ))->toContain('notifications:write');

    expect(RequiredScopesExtension::extractScopesFromController(
        Core\Notification\Presentation\Http\Controllers\GetNotificationStatusController::class,
    ))->toContain('notifications:read');
});

it('walks the inheritance chain so empty subclasses inherit parent scopes', function (): void {
    // StartPaylinkPaymentController has an empty body — the scope
    // check lives in AbstractStartPaymentController. The extension
    // must walk the chain, otherwise the doc for /payments/paylink
    // would ship without any required scope.
    $scopes = RequiredScopesExtension::extractScopesFromController(
        Core\Payment\Presentation\Http\Controllers\StartPaylinkPaymentController::class,
    );

    expect($scopes)->toContain('payments:write');
});

it('uses underscore-form for channel-session scopes (matches the literal the controllers check)', function (): void {
    // Controllers hardcode 'notification_channel_sessions:write/read' with
    // underscores. A caller who registers 'notification-channel-sessions:write'
    // (hyphens) gets 403 — the doc must surface the exact literal.
    expect(RequiredScopesExtension::extractScopesFromController(
        Core\Notification\Presentation\Http\Controllers\Waha\RequestChannelSessionPairingCodeController::class,
    ))->toContain('notification_channel_sessions:write');

    expect(RequiredScopesExtension::extractScopesFromController(
        Core\Notification\Presentation\Http\Controllers\Waha\GetChannelSessionQrCodeController::class,
    ))->toContain('notification_channel_sessions:read');

    expect(RequiredScopesExtension::extractScopesFromController(
        Core\Notification\Presentation\Http\Controllers\Waha\GetChannelSessionScreenshotController::class,
    ))->toContain('notification_channel_sessions:read');
});

it('returns a deduplicated sorted list when called repeatedly on the same controller', function (): void {
    $scopes = RequiredScopesExtension::extractScopesFromController(
        Core\Webhook\Presentation\Http\Controllers\DeactivateWebhookEndpointController::class,
    );

    expect($scopes)->toBe(['webhooks:write']);
});
