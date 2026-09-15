<?php

declare(strict_types=1);

namespace Core\Identity;

/**
 * Platform operator roles (spec fonctionnelle §8.1 — described there as
 * "indicatif"). A fixed PHP enum rather than the dynamic
 * roles/permissions/role_permissions/operator_roles schema spec technique
 * §5.1 literally names: an explicit YAGNI call (ADR-0044) — there is no
 * real need yet for operators to compose custom role sets, and a fixed
 * enum is trivially migratable to a dynamic schema later if that changes.
 *
 * Lives top-level, outside all four layer directories, exactly like
 * `PaymentStatus`/`NotificationStatus`: Deptrac's layer rules only cover
 * `Domain/Application/Infrastructure/Presentation`, so a type placed here
 * is reachable from both `Contracts` and `Domain` without either ever
 * importing the other (G3b stays intact).
 */
enum OperatorRole: string
{
    case PlatformAdmin = 'platform_admin';
    case FinanceOperator = 'finance_operator';
    case SupportOperator = 'support_operator';
    case ProviderManager = 'provider_manager';
    case ReadOnlyAuditor = 'read_only_auditor';

    /**
     * Fixed `resource:action` permission strings — the same shape as a
     * Project API key's scopes (`ApiKey::hasScope()`). Deliberately
     * limited to what Lot 6's first slice actually gates; a role's list
     * grows only when a real endpoint needs to check for it.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::PlatformAdmin => ['*'],
            self::FinanceOperator => ['payments:reconcile:read', 'payments:reconcile:write', 'audit:read'],
            self::SupportOperator => ['webhooks:read', 'webhooks:replay', 'audit:read'],
            self::ProviderManager => ['audit:read'],
            self::ReadOnlyAuditor => ['audit:read'],
        };
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions();

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}
