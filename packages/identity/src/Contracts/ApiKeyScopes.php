<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * Every scope a Project API key can carry, and the templates the console
 * offers so nobody has to pick scopes one by one (ADR-0082 §2).
 *
 * A key carries only what it was issued with: adding a scope here never
 * widens an existing key (least privilege). `*` remains accepted for
 * compatibility and is shown as « accès total » with a warning.
 */
final class ApiKeyScopes
{
    /** @var list<string> */
    public const ALL = [
        'payments:read',
        'payments:write',
        'webhooks:read',
        'webhooks:write',
        'notifications:read',
        'notifications:write',
        'notification_channel_sessions:read',
        'notification_channel_sessions:write',
        'wallets:read',
        // ADR-0065 — transfers and recorded withdrawals.
        'wallets:write',
        // ADR-0082 §2 — SaaS commerce, portal, payouts, exports.
        'customers:read',
        'customers:write',
        'plans:read',
        'plans:write',
        'subscriptions:read',
        'subscriptions:write',
        'invoices:read',
        'invoices:write',
        'usage:read',
        'usage:write',
        'portal:write',
        'payouts:read',
        'payouts:write',
        'exports:write',
        // ADR-0122 §8 — the declared form of the tenant. It describes a
        // **form**, it writes no customer, no subscription and no invoice.
        'tenant_schema:write',
    ];

    /**
     * The wildcard scope — covers every concrete scope. Same rule as
     * `OperatorRole::hasPermission()`.
     */
    public const WILDCARD = '*';

    public const TEMPLATE_SERVER = 'server';

    public const TEMPLATE_READ_ONLY = 'read_only';

    public const TEMPLATE_PORTAL = 'portal';

    public const TEMPLATE_PAYOUTS = 'payouts';

    public const TEMPLATE_CLIENT_APP = 'client_app';

    /** Console labels, in display order. */
    public const TEMPLATES = [
        self::TEMPLATE_SERVER => 'Serveur complet',
        self::TEMPLATE_READ_ONLY => 'Lecture seule',
        self::TEMPLATE_PORTAL => 'Portail uniquement',
        self::TEMPLATE_CLIENT_APP => 'Application cliente',
        self::TEMPLATE_PAYOUTS => 'Versements',
    ];

    /**
     * @return list<string>
     */
    public static function template(string $template): array
    {
        return match ($template) {
            // Moving money out deserves its own key (ADR-0082 §2).
            self::TEMPLATE_SERVER => array_values(array_diff(self::ALL, ['payouts:write'])),
            self::TEMPLATE_READ_ONLY => array_values(array_filter(self::ALL, static fn (string $s): bool => str_ends_with($s, ':read'))),
            self::TEMPLATE_PORTAL => ['portal:write', 'customers:read'],
            // ADR-0107 §5 — what a client application needs, and the exact
            // limit of its power: it reads its rights, counts its usage,
            // opens its portal, reads its commercial state. It holds
            // **no** write scope on the commerce — not `customers:write`,
            // not `subscriptions:write`, not `invoices:write`. The commerce
            // happens in the portal, by the owner.
            self::TEMPLATE_CLIENT_APP => [
                'customers:read',
                'usage:read',
                'usage:write',
                'portal:write',
                'subscriptions:read',
                'invoices:read',
                // Les messages ne sont pas du commerce : l'application
                // cliente prévient **ses propres** clients, et le Core n'est
                // que la passerelle WhatsApp. C'est la clé du projet qui
                // envoie, et c'est normal — sans ces deux portées, le premier
                // client réel ne peut pas écrire un seul message.
                'notifications:write',
                'notifications:read',
                // ADR-0122 §8 — the application declares the **form** of
                // its tenant: the key it designates, the pattern its
                // references must respect, the attributes it expects the
                // Core to collect. That is a form, not a write on the
                // commerce: the guarantee of ADR-0107 §5 stays whole.
                'tenant_schema:write',
            ],
            self::TEMPLATE_PAYOUTS => ['payouts:read', 'payouts:write'],
            default => [],
        };
    }

    /**
     * @param list<string> $scopes
     * @return list<string> the scopes that are neither known nor the wildcard
     */
    public static function unknown(array $scopes): array
    {
        return array_values(array_filter(
            $scopes,
            static fn (string $scope): bool => $scope !== self::WILDCARD && ! in_array($scope, self::ALL, true),
        ));
    }
}
