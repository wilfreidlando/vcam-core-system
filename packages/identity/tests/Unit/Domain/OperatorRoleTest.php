<?php

declare(strict_types=1);

use Core\Identity\OperatorRole;

it('grants platform admin every permission via the wildcard', function (): void {
    expect(OperatorRole::PlatformAdmin->hasPermission('audit:read'))->toBeTrue()
        ->and(OperatorRole::PlatformAdmin->hasPermission('anything:at-all'))->toBeTrue();
});

it('grants a non-admin role only its own listed permissions', function (): void {
    expect(OperatorRole::SupportOperator->hasPermission('webhooks:read'))->toBeTrue()
        ->and(OperatorRole::SupportOperator->hasPermission('webhooks:replay'))->toBeTrue()
        ->and(OperatorRole::SupportOperator->hasPermission('payments:reconcile:write'))->toBeFalse();
});

it('grants read-only auditor every read permission but no write/replay action', function (): void {
    expect(OperatorRole::ReadOnlyAuditor->hasPermission('audit:read'))->toBeTrue()
        ->and(OperatorRole::ReadOnlyAuditor->hasPermission('webhooks:read'))->toBeTrue()
        ->and(OperatorRole::ReadOnlyAuditor->hasPermission('webhooks:replay'))->toBeFalse()
        ->and(OperatorRole::ReadOnlyAuditor->hasPermission('payments:reconcile:write'))->toBeFalse();
});
