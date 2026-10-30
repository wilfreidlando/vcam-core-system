<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

use Core\Portal\Presentation\Http\Middleware\AuthenticatePortal;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Architecture\Idor\PortalOutcome as Outcome;
use Tests\Architecture\Idor\PortalResourceNature as Nature;
use Tests\Architecture\Idor\PortalRouteEntry as Entry;

/**
 * G-10 — the manifest of every portal route that carries an identifier
 * (audit 2026-10-29 finding #20: the generated IDOR manifest covered the
 * console only, and the customer-facing portal — where an IDOR is the most
 * probable — had none).
 *
 * Exhaustive by construction: `PortalIdorGuardTest` walks the live routing
 * table (`discoverPortalRoutesWithIdentifier()`) and fails when a portal
 * route with a path parameter is missing here. `PortalIdorTest` turns every
 * entry into a generated request.
 *
 * The tenant of the portal is the **customer** (ADR-0075 §3): a project
 * hosts many customers, and the neighbour of the customer under test lives
 * in the **same** project. That is the boundary a forgotten `customerId`
 * check would break — and the one the console's project boundary cannot
 * catch.
 *
 * Reading an entry:
 *  - `isolated(route, nature, expected)` — the neighbour's resource under
 *    the session of the customer under test → `expected` (404, or the
 *    portal's refusal, which is the very same page as for a resource that
 *    does not exist — see `PortalOutcome`);
 *  - `control: true` — positive control: the customer's own resource is
 *    reachable (proves the route does not simply refuse everybody);
 *  - `oneTimeLink(...)` — the access link: single use, expiry, and bound
 *    to its project and its customer;
 *  - `publicDoor(...)` — a public door: it answers exactly like a project
 *    that does not exist, so it discloses nothing.
 */
final class PortalRouteManifest
{
    /**
     * The middleware that makes a route part of the customer portal.
     */
    public const PORTAL_MIDDLEWARE = [AuthenticatePortal::class];

    /**
     * The URI prefix of the portal. The public doors (the one-time link,
     * the login by code) live under it **without** the session middleware:
     * they are doors, not pages of a session.
     */
    public const PORTAL_PREFIX = 'portal/';

    private const ENTRY_LINK_REASON = 'Le lien d\'accès ne désigne pas une ressource d\'un tenant : c\'est un secret '
        .'qui ouvre UNE session, celle du projet et du client pour lesquels il a été émis (ADR-0075 §1). Ses '
        .'propriétés — usage unique, expiration, liaison au projet et au client — sont prouvées par PortalIdorTest, '
        .'pas par une comparaison entre deux clients.';

    private const LOGIN_DOOR_REASON = 'Une porte publique : elle porte l\'identifiant d\'un projet, mais elle n\'ouvre '
        .'aucune session et ne rend aucune donnée d\'un client. La connexion par code est éteinte par défaut '
        .'(ADR-0100) et, même ouverte, elle répond la même page que le numéro soit connu ou non (DirectLoginService '
        .'cherche le numéro APRÈS la réponse). L\'égalité des réponses pour un projet étranger et pour un projet '
        .'inexistant est prouvée par PortalIdorTest.';

    /**
     * @return list<Entry>
     */
    public static function entries(): array
    {
        return [
            // ── The one-time access link (ADR-0075 §1) ─────────────────────
            Entry::oneTimeLink('portal.enter', Nature::EntryLink, self::ENTRY_LINK_REASON),

            // ── The public doors of the login by code (ADR-0075 §2) ─────────
            Entry::publicDoor('portal.login', Nature::Project, self::LOGIN_DOOR_REASON),
            Entry::publicDoor('portal.login.send', Nature::Project, self::LOGIN_DOOR_REASON),
            Entry::publicDoor('portal.login.verify', Nature::Project, self::LOGIN_DOOR_REASON),

            // ── The invoices (ADR-0073) ─────────────────────────────────────
            // A read answers 404; the payment of a foreign invoice answers
            // 404 too (the page « Introuvable »), never a refusal that
            // would confirm the invoice exists somewhere.
            Entry::isolated('portal.invoice', Nature::Invoice, Outcome::NotFound, control: true),
            Entry::isolated('portal.invoice.pay', Nature::Invoice, Outcome::NotFound, control: true),

            // ── The subscriptions (ADR-0072) ────────────────────────────────
            // A mutation answers the portal's refusal — the very same page
            // as for an identifier nobody ever had (« Cet abonnement est
            // introuvable. »), so it confirms nothing either.
            Entry::isolated('portal.subscriptions.cancel', Nature::Subscription, Outcome::Refused, control: true),
            Entry::isolated('portal.subscriptions.change-plan', Nature::Subscription, Outcome::Refused, control: true),
            Entry::isolated('portal.subscriptions.keep', Nature::Subscription, Outcome::Refused, control: true),
            Entry::isolated('portal.subscriptions.renew', Nature::Subscription, Outcome::Refused, control: true),

            // ── The offers of the catalogue (ADR-0071) ──────────────────────
            // A customer subscribes alone to a **public** offer of her
            // project; an offer of another project — like a private offer of
            // her own — is not proposed to her.
            Entry::isolated('portal.plans.subscribe', Nature::Plan, Outcome::Refused, control: true),
        ];
    }

    /**
     * @return list<Entry>
     */
    public static function withCoverage(PortalCoverage $coverage): array
    {
        return array_values(array_filter(self::entries(), static fn (Entry $entry): bool => $entry->coverage === $coverage));
    }

    /**
     * Every route of the live routing table that belongs to the portal and
     * carries a path parameter.
     *
     * @return array<string, Route> keyed by route name (an unnamed route is keyed by its URI)
     */
    public static function discoverPortalRoutesWithIdentifier(): array
    {
        $discovered = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if ($route->parameterNames() === [] || ! self::isPortalPlane($route)) {
                continue;
            }

            $discovered[$route->getName() ?? 'UNNAMED '.$route->uri()] = $route;
        }

        return $discovered;
    }

    /**
     * The portal has two planes, and the manifest must cover both: the
     * pages behind a customer session (`AuthenticatePortal`) and the public
     * doors of the prefix `/portal` (the one-time link, the login by code).
     * A discovery that only saw one of them would leave the other
     * unguarded — and the console's discovery, which is a different
     * middleware altogether, stays untouched.
     */
    public static function isPortalPlane(Route $route): bool
    {
        return array_intersect(self::PORTAL_MIDDLEWARE, self::middlewareOf($route)) !== []
            || str_starts_with($route->uri(), self::PORTAL_PREFIX);
    }

    /**
     * @return list<string>
     */
    private static function middlewareOf(Route $route): array
    {
        return array_values(array_filter($route->gatherMiddleware(), is_string(...)));
    }
}
