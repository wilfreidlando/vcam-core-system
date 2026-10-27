<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

/**
 * ADR-0069 §1 — the role a console account holds **inside** an
 * organization. A fixed enum, like `OperatorRole` (ADR-0044): five stable
 * roles beat a dynamic permissions schema (the same YAGNI call).
 */
enum OrganizationRole: string
{
    case Owner = 'org_owner';
    case Admin = 'org_admin';
    case Finance = 'org_finance';
    case Support = 'org_support';
    case Viewer = 'org_viewer';

    /**
     * `resource:action` permissions, the same shape as a Project API key's
     * scopes and an operator's platform permissions.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => ['*'],
            self::Admin => [
                'projects:read', 'projects:write',
                'api-keys:read', 'api-keys:write',
                'webhook-endpoints:read', 'webhook-endpoints:write',
                'provider-applications:read', 'provider-applications:write',
                'channel-sessions:read', 'channel-sessions:write',
                'customers:read', 'customers:write',
                'plans:read', 'plans:write',
                'subscriptions:read', 'subscriptions:write',
                'invoices:read', 'invoices:write',
                'usage:read',
                'portal:write',
                'members:read', 'members:write',
                'audit:read',
            ],
            self::Finance => [
                // ADR-0092 §4 — the console's commerce plane (customers,
                // plans, subscriptions) is reached from the project page:
                // the role that manages a project's contracts must be able
                // to open that project.
                'projects:read',
                'customers:read', 'customers:write',
                'subscriptions:read', 'subscriptions:write',
                'invoices:read', 'invoices:write',
                'payments:read', 'payments:reconcile:write',
                'wallets:read', 'money-exceptions:read', 'money-exceptions:write',
                'audit:read',
            ],
            self::Support => [
                // ADR-0092 §4 — same reason as `org_finance`: the support
                // role is the one that suspends a customer's subscription
                // (spec §8.1), and the subscription lives on a project.
                'projects:read',
                'customers:read',
                'subscriptions:read', 'subscriptions:write',
                'invoices:read',
                'payments:read',
                'notifications:read',
                'webhooks:read', 'webhooks:replay', 'webhook-endpoints:read',
                'audit:read',
            ],
            self::Viewer => [
                'projects:read',
                'customers:read',
                'subscriptions:read',
                'invoices:read',
                'usage:read',
                'payments:read',
                'wallets:read',
                'notifications:read',
                'webhooks:read',
                'audit:read',
            ],
        };
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions();

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}
