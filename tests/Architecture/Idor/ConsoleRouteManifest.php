<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

use App\Http\Middleware\ResolveOperatorSession;
use Core\Identity\Presentation\Http\Middleware\ResolveOperatorMiddleware;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Architecture\Idor\ExpectedOutcome as Outcome;
use Tests\Architecture\Idor\IdorRouteEntry as Entry;
use Tests\Architecture\Idor\ResourceNature as Nature;

/**
 * G15 — the manifest of every console route that carries an identifier
 * (ADR-0069 §Sécurité: "chaque route console à identifiant reçoit un test
 * généré « compte de l'organisation A sur une ressource de B → 404 »";
 * ADR-0081 S-01/S-04).
 *
 * Exhaustive by construction: `IdorGuardTest` walks the live routing table
 * (`discoverConsoleRoutesWithIdentifier()`) and fails when a console route
 * with a path parameter is missing here. `ConsoleIdorTest` turns every
 * entry into a generated request.
 *
 * Reading an entry:
 *  - `isolated(route, nature, expected)` — B's resource under B's project →
 *    `expected` (404, or the web console's non-leaking "introuvable"
 *    redirect, see `ExpectedOutcome`);
 *  - `ownParent:` — B's child resource addressed under A's project or
 *    organization (`/projects/{A}/api-keys/{key of B}`) → that answer;
 *  - `control: true` — positive control: A's own resource is reachable
 *    (proves the route does not simply 404 everything);
 *  - `platformReserved(route, nature, reason)` — not an isolation case:
 *    refused (403) to any organization account before any lookup.
 */
final class ConsoleRouteManifest
{
    /**
     * The path parameters that name the tenant a nested resource is
     * addressed under.
     */
    public const PARENT_PARAMETERS = ['projectId', 'organizationId'];

    /**
     * The middleware that makes a route part of the operator console, by
     * alias and by class.
     */
    private const CONSOLE_MIDDLEWARE = [
        'operator.auth', 'operator.session', ResolveOperatorMiddleware::class, ResolveOperatorSession::class,
    ];

    private const OPERATORS_REASON = 'Ressource de la plateforme : un compte console n\'appartient à aucune organisation '
        .'(ADR-0069 §1, la gestion des comptes console reste réservée à la plateforme). OperatorsController et '
        .'Update/RevokeOperatorController vérifient `hasPermission(\'operators:write\')`, vrai pour un rôle de plateforme '
        .'seulement : un compte d\'organisation reçoit 403 avant toute lecture, quel que soit l\'identifiant.';

    private const GLOBAL_SESSIONS_REASON = 'Ressource de la plateforme : une session WhatsApp globale n\'appartient à aucun '
        .'projet (ADR-0057, ADR-0069 §2). ChannelSessionsController::authorize() sans projectId exige '
        .'`hasPermission(\'channel-sessions:write\')` (rôle de plateforme) : 403 avant toute lecture pour un compte '
        .'d\'organisation, quel que soit l\'identifiant.';

    private const ORGANIZATION_EDIT_REASON = 'Créer/modifier une organisation reste une action de plateforme (ADR-0069 §2) : '
        .'OrganizationsController::edit/update exigent `hasPermission(\'projects:write\')`, vrai pour un rôle de plateforme '
        .'seulement — un compte d\'organisation, même propriétaire, reçoit 403 avant la résolution de l\'organisation, '
        .'pour la sienne comme pour celle d\'un autre.';

    private const SESSION_REF_IS_PROJECT_KEYED = 'Une session de projet est désignée par le couple (projectId, sessionRef) : '
        .'sous le projet A, le sessionRef de B désigne simplement une session de A qui n\'existe pas — il n\'y a pas '
        .'d\'identifiant global de B à détourner.';

    /**
     * @return list<Entry>
     */
    public static function entries(): array
    {
        return [
            // ── Organizations and their members ────────────────────────────
            Entry::platformReserved('admin.organizations.edit', Nature::Organization, self::ORGANIZATION_EDIT_REASON),
            Entry::platformReserved('admin.organizations.update', Nature::Organization, self::ORGANIZATION_EDIT_REASON),
            Entry::isolated('admin.organizations.members.index', Nature::Organization, Outcome::NotFound, control: true),
            Entry::isolated('admin.organizations.members.invite', Nature::Organization, Outcome::NotFound, control: true),
            Entry::isolated('admin.organizations.members.store', Nature::Organization, Outcome::NotFound),
            Entry::isolated('admin.organizations.members.revoke.web', Nature::OrganizationMember, Outcome::NotFound, ownParent: Outcome::NotFound),
            Entry::isolated('admin.organizations.members.role.web', Nature::OrganizationMember, Outcome::NotFound, ownParent: Outcome::NotFound, control: true),
            Entry::isolated('admin.organizations.members', Nature::Organization, Outcome::NotFound, control: true),
            Entry::isolated('admin.organizations.invitations.create', Nature::Organization, Outcome::NotFound),
            Entry::isolated('admin.organizations.members.update', Nature::OrganizationMember, Outcome::NotFound, ownParent: Outcome::NotFound, control: true),
            Entry::isolated('admin.organizations.members.revoke', Nature::OrganizationMember, Outcome::NotFound, ownParent: Outcome::NotFound),

            // ── Projects ───────────────────────────────────────────────────
            Entry::isolated('admin.projects.show', Nature::Project, Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.edit', Nature::Project, Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.update', Nature::Project, Outcome::NotFound),

            // ── API keys ───────────────────────────────────────────────────
            Entry::isolated('admin.projects.api-keys.create', Nature::ApiKey, Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.api-keys.store', Nature::ApiKey, Outcome::NotFound),
            Entry::isolated('admin.projects.api-keys.rotate', Nature::ApiKey, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.api-keys.revoke', Nature::ApiKey, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),

            // ── Provider applications ──────────────────────────────────────
            Entry::isolated('admin.projects.provider-applications.create', Nature::ProviderApplication, Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.provider-applications.store', Nature::ProviderApplication, Outcome::NotFound),
            Entry::isolated('admin.projects.provider-applications.edit', Nature::ProviderApplication, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect, control: true),
            Entry::isolated('admin.projects.provider-applications.delete', Nature::ProviderApplication, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),

            // ── Webhook endpoints ──────────────────────────────────────────
            Entry::isolated('admin.projects.webhook-endpoints.create', Nature::WebhookEndpoint, Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.webhook-endpoints.store', Nature::WebhookEndpoint, Outcome::NotFound),
            Entry::isolated('admin.projects.webhook-endpoints.update', Nature::WebhookEndpoint, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.webhook-endpoints.activate', Nature::WebhookEndpoint, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.webhook-endpoints.deactivate', Nature::WebhookEndpoint, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.webhook-endpoints.rotate-secret', Nature::WebhookEndpoint, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.webhook-endpoints.revoke-previous-secret', Nature::WebhookEndpoint, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),

            // ── WhatsApp sessions of a project ─────────────────────────────
            Entry::isolated('admin.projects.channel-sessions.index', Nature::ChannelSession, Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.channel-sessions.store', Nature::ChannelSession, Outcome::NotFound),
            Entry::isolated('admin.projects.channel-sessions.qr', Nature::ChannelSession, Outcome::NotFound, ownParentSkipped: self::SESSION_REF_IS_PROJECT_KEYED),
            Entry::isolated('admin.projects.channel-sessions.screenshot', Nature::ChannelSession, Outcome::NotFound, ownParentSkipped: self::SESSION_REF_IS_PROJECT_KEYED),
            Entry::isolated('admin.projects.channel-sessions.status', Nature::ChannelSession, Outcome::NotFound, ownParentSkipped: self::SESSION_REF_IS_PROJECT_KEYED),
            Entry::isolated('admin.projects.channel-sessions.pairing-code', Nature::ChannelSession, Outcome::NotFound, ownParentSkipped: self::SESSION_REF_IS_PROJECT_KEYED),
            Entry::isolated('admin.projects.channel-sessions.delete', Nature::ChannelSession, Outcome::NotFound, ownParentSkipped: self::SESSION_REF_IS_PROJECT_KEYED),

            // ── Wallets, notifications (web) ───────────────────────────────
            Entry::isolated('admin.projects.wallets.index', Nature::Wallet, Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.wallets.ledger', Nature::Wallet, Outcome::NotFound, ownParent: Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.notifications.index', Nature::Notification, Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.notifications.show', Nature::Notification, Outcome::NotFound, ownParent: Outcome::NotFound, control: true),

            // ── Commerce of a Project (ADR-0070 / ADR-0071 / ADR-0072) ──
            // A **read** answers 404; a **mutation** answers the console's
            // "introuvable" redirect — the operator keeps their place
            // (the same idiom as the rotation of an API key). Neither
            // ever answers 403, which would confirm that the resource
            // exists somewhere (ADR-0081 S-04).
            Entry::isolated('admin.projects.customers.index', Nature::Customer, Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.customers.new', Nature::Customer, Outcome::NotFound),
            Entry::isolated('admin.projects.customers.store', Nature::Customer, Outcome::NotFound),
            Entry::isolated('admin.projects.customers.edit', Nature::Customer, Outcome::NotFound, ownParent: Outcome::NotFound),
            Entry::isolated('admin.projects.customers.update.web', Nature::Customer, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.customers.suspend.web', Nature::Customer, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.customers.reactivate.web', Nature::Customer, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.customers.erase.web', Nature::Customer, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),

            Entry::isolated('admin.projects.plans.index', Nature::Plan, Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.plans.new', Nature::Plan, Outcome::NotFound),
            Entry::isolated('admin.projects.plans.store', Nature::Plan, Outcome::NotFound),
            Entry::isolated('admin.projects.plans.versions.store', Nature::Plan, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.plans.versions.publish.web', Nature::Plan, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.plans.archive.web', Nature::Plan, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),

            Entry::isolated('admin.projects.subscriptions.index', Nature::Subscription, Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.subscriptions.show', Nature::Subscription, Outcome::NotFound, ownParent: Outcome::NotFound),
            Entry::isolated('admin.projects.subscriptions.pause.web', Nature::Subscription, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.subscriptions.resume.web', Nature::Subscription, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.subscriptions.cancel.web', Nature::Subscription, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.subscriptions.change-plan.web', Nature::Subscription, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.subscriptions.complimentary.web', Nature::Subscription, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.subscriptions.pay.web', Nature::Subscription, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),

            // The invoices (ADR-0073) follow the same rule: a **read**
            // answers 404, a **mutation** answers the console's
            // "introuvable" redirect.
            Entry::isolated('admin.projects.invoices.index', Nature::Invoice, Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.invoices.new', Nature::Invoice, Outcome::NotFound),
            Entry::isolated('admin.projects.invoices.store', Nature::Invoice, Outcome::NotFound),
            Entry::isolated('admin.projects.invoices.show', Nature::Invoice, Outcome::NotFound, ownParent: Outcome::NotFound),
            Entry::isolated('admin.projects.invoices.issue.web', Nature::Invoice, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.invoices.void.web', Nature::Invoice, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),
            Entry::isolated('admin.projects.invoices.uncollectible.web', Nature::Invoice, Outcome::NotFound, ownParent: Outcome::NotFoundRedirect),

            // ── Wallets, notifications, payments of a project (JSON) ───────
            Entry::isolated('admin.projects.wallets', Nature::Wallet, Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.notifications', Nature::Notification, Outcome::NotFound, control: true),
            Entry::isolated('admin.projects.payments', Nature::Payment, Outcome::NotFound, control: true),

            // ── Payments ───────────────────────────────────────────────────
            // `?project_id=` is the parent here: B's payment under B's project,
            // then under A's.
            Entry::isolated('admin.payments.show', Nature::Payment, Outcome::NotFoundRedirect, ownParent: Outcome::NotFoundRedirect, control: true),
            Entry::isolated('admin.reconciliation.run-one', Nature::Payment, Outcome::NotFoundRedirect),

            // ── Webhook deliveries ─────────────────────────────────────────
            Entry::isolated('admin.webhook-deliveries.show', Nature::WebhookDelivery, Outcome::NotFoundRedirect, control: true),
            Entry::isolated('admin.webhook-deliveries.replay.web', Nature::WebhookDelivery, Outcome::NotFoundRedirect, control: true),
            Entry::isolated('admin.webhook-deliveries.replay', Nature::WebhookDelivery, Outcome::NotFound, control: true),

            // ── Money exceptions ───────────────────────────────────────────
            Entry::isolated('admin.money-exceptions.show', Nature::MoneyException, Outcome::NotFound, control: true),
            Entry::isolated('admin.money-exceptions.resolve', Nature::MoneyException, Outcome::NotFound, control: true),
            Entry::isolated('admin.money-exceptions.approve', Nature::MoneyException, Outcome::NotFound),
            Entry::isolated('admin.money-exceptions.reject', Nature::MoneyException, Outcome::NotFound),

            // ── Ledger adjustments (ADR-0076 §6) ─────────────────────────
            // `approve` and `reject` MOVE MONEY (or close the door on it):
            // like `admin.money-exceptions.resolve`, they carry a positive
            // control, so a route that refuses everything — a scope
            // inverted, a systematic refusal — cannot pass the isolation
            // case unnoticed.
            Entry::isolated('admin.ledger-adjustments.show', Nature::LedgerAdjustment, Outcome::NotFound, control: true),
            Entry::isolated('admin.ledger-adjustments.approve', Nature::LedgerAdjustment, Outcome::NotFound, control: true),
            Entry::isolated('admin.ledger-adjustments.reject', Nature::LedgerAdjustment, Outcome::NotFound, control: true),

            // ── Platform resources ─────────────────────────────────────────
            Entry::platformReserved('admin.operators.edit', Nature::Operator, self::OPERATORS_REASON),
            Entry::platformReserved('admin.operators.update.web', Nature::Operator, self::OPERATORS_REASON),
            Entry::platformReserved('admin.operators.revoke.web', Nature::Operator, self::OPERATORS_REASON),
            Entry::platformReserved('admin.operators.update', Nature::Operator, self::OPERATORS_REASON),
            Entry::platformReserved('admin.operators.revoke', Nature::Operator, self::OPERATORS_REASON),
            Entry::platformReserved('admin.channel-sessions.qr', Nature::GlobalChannelSession, self::GLOBAL_SESSIONS_REASON),
            Entry::platformReserved('admin.channel-sessions.screenshot', Nature::GlobalChannelSession, self::GLOBAL_SESSIONS_REASON),
            Entry::platformReserved('admin.channel-sessions.status', Nature::GlobalChannelSession, self::GLOBAL_SESSIONS_REASON),
            Entry::platformReserved('admin.channel-sessions.pairing-code', Nature::GlobalChannelSession, self::GLOBAL_SESSIONS_REASON),
            Entry::platformReserved('admin.channel-sessions.delete', Nature::GlobalChannelSession, self::GLOBAL_SESSIONS_REASON),
        ];
    }

    /**
     * @return list<Entry>
     */
    public static function withCoverage(IdorCoverage $coverage): array
    {
        return array_values(array_filter(self::entries(), static fn (Entry $entry): bool => $entry->coverage === $coverage));
    }

    /**
     * Every route of the live routing table that belongs to the operator
     * console and carries a path parameter.
     *
     * @return array<string, Route> keyed by route name (an unnamed route is keyed by its URI)
     */
    public static function discoverConsoleRoutesWithIdentifier(): array
    {
        $discovered = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if ($route->parameterNames() === [] || array_intersect(self::CONSOLE_MIDDLEWARE, self::middlewareOf($route)) === []) {
                continue;
            }

            $discovered[$route->getName() ?? 'UNNAMED '.$route->uri()] = $route;
        }

        return $discovered;
    }

    public static function planeOf(Route $route): ConsolePlane
    {
        return array_intersect(['operator.session', ResolveOperatorSession::class], self::middlewareOf($route)) !== []
            ? ConsolePlane::Web
            : ConsolePlane::Json;
    }

    /**
     * @return list<string>
     */
    private static function middlewareOf(Route $route): array
    {
        return array_values(array_filter($route->gatherMiddleware(), is_string(...)));
    }
}
