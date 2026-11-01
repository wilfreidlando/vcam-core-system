<?php

declare(strict_types=1);

namespace Core\Portal\Application\UseCase;

use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Portal\Application\Dto\ExchangedPortalSession;
use Core\Portal\Application\Dto\PortalBranding;
use Core\Portal\Application\Dto\PortalPrincipal;
use Core\Portal\Domain\Model\PortalSession;
use Core\Portal\Domain\Model\PortalSettings;
use Core\Portal\Domain\Port\PortalSessionRepository;
use Core\Portal\Domain\Port\PortalSettingsRepository;
use Core\Portal\Domain\Port\SecretHasher;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Core\Support\Identifier;

/**
 * ADR-0075 §1, §Sécurité — the life of a browser session.
 *
 * `exchange()` turns the one-time link into a cookie (a *new* secret: the
 * link given to the customer never becomes the cookie, so a leaked link is
 * worthless once used and there is no session fixation). `authenticate()`
 * applies the idle (30 min) and absolute (8 h) limits on every request.
 */
final readonly class PortalSessionService
{
    public function __construct(
        private PortalSessionRepository $sessions,
        private PortalSettingsRepository $settings,
        private SecretHasher $hasher,
        private Clock $clock,
        private GetCustomer $customers,
        private Identifier $identifier,
    ) {}

    public function exchange(string $token): ?ExchangedPortalSession
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        $now = $this->clock->now();
        $cookie = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $session = $this->sessions->exchange(
            $this->hasher->hash($token, 'link'),
            $this->hasher->hash($cookie, 'cookie'),
            $now,
            $now->modify('+'.PortalSession::ABSOLUTE_TTL_SECONDS.' seconds'),
        );

        if ($session === null) {
            return null;
        }

        $landing = $session->flow === PortalSession::FLOW_PAY_INVOICE && $session->flowRef !== null
            ? '/portal/invoices/'.rawurlencode($session->flowRef)
            : '/portal';

        return new ExchangedPortalSession($cookie, $landing, PortalSession::ABSOLUTE_TTL_SECONDS);
    }

    /**
     * ADR-0075 §2 — the login by code ends here: a session is opened for a
     * customer the project (not a caller) vouched for by a code, and goes
     * through the very same exchange as the one-time link. The link is
     * created and spent inside this call, so it never reaches anyone.
     */
    public function openForCustomer(string $projectId, string $customerId, string $customerRef, ?string $ip): ?ExchangedPortalSession
    {
        $now = $this->clock->now();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->sessions->create(new PortalSession(
            id: $this->identifier->generate(),
            projectId: $projectId,
            customerId: $customerId,
            customerRef: $customerRef,
            flow: PortalSession::FLOW_MANAGE,
            flowRef: null,
            returnUrl: null,
            tokenExpiresAt: $now->modify('+'.PortalSession::TOKEN_TTL_SECONDS.' seconds'),
            createdAt: $now,
        ), $this->hasher->hash($token, 'link'), $ip);

        return $this->exchange($token);
    }

    public function authenticate(?string $cookie): ?PortalPrincipal
    {
        if ($cookie === null || $cookie === '' || strlen($cookie) > 128) {
            return null;
        }

        $now = $this->clock->now();
        $session = $this->sessions->findLive(
            $this->hasher->hash($cookie, 'cookie'),
            $now,
            $now->modify('-'.PortalSession::IDLE_TTL_SECONDS.' seconds'),
        );

        if ($session === null) {
            return null;
        }

        // A session outlives nothing it was opened for: an erased customer
        // loses its portal at once, not when the cookie expires (8 h).
        $customer = $this->customers->get(new GetCustomerCommand($session->projectId, $session->customerRef), AccessScope::forProject($session->projectId));

        if ($customer === null || $customer->erasedAt !== null) {
            $this->sessions->revoke($session->id, $now);

            return null;
        }

        $this->sessions->touch($session->id, $now);
        $settings = $this->settings->find($session->projectId) ?? PortalSettings::defaultsFor($session->projectId);

        return new PortalPrincipal(
            $session->id,
            $session->projectId,
            $session->customerId,
            $session->customerRef,
            $session->flow,
            $session->flowRef,
            $session->returnUrl,
            new PortalBranding(
                $settings->displayName,
                $settings->publicLogoUrl(),
                $settings->primaryColor,
                $settings->publicSupportUrl(),
                $settings->locale,
                $settings->allowSelfCancel,
                $settings->paymentChannels,
                $settings->allowPlanChange,
            ),
        );
    }

    public function end(PortalPrincipal $principal): void
    {
        $this->sessions->revoke($principal->sessionId, $this->clock->now());
    }

    /**
     * The anti-forgery token of a session: derived, never stored, different
     * for every session.
     */
    public function csrfToken(PortalPrincipal $principal): string
    {
        return $this->hasher->hash($principal->sessionId, 'csrf');
    }

    public function isValidCsrf(PortalPrincipal $principal, ?string $given): bool
    {
        return is_string($given) && hash_equals($this->csrfToken($principal), $given);
    }

    /**
     * The public brand of a project, for pages shown before any session.
     */
    public function brandingOf(string $projectId): PortalBranding
    {
        $settings = $this->settings->find($projectId) ?? PortalSettings::defaultsFor($projectId);

        return new PortalBranding($settings->displayName, $settings->publicLogoUrl(), $settings->primaryColor, $settings->publicSupportUrl(), $settings->locale, $settings->allowSelfCancel, $settings->paymentChannels, $settings->allowPlanChange);
    }

    /**
     * The brand colour the public pages may paint before any session, for
     * the project the page belongs to.
     *
     * Audit #11 (garde-fou G-9) — the stylesheet is public: it is fetched
     * by the browser of the customer, before any session exists, on a
     * project named in the query. It must therefore answer the same thing
     * to everything an anonymous caller may send — an unknown project, a
     * project that never chose a portal look, a malformed value — or it
     * would be an oracle telling those apart. Only a project that really
     * has portal settings answers its own colour; the others answer the
     * neutral palette, which is the very same bytes.
     */
    public function publicPrimaryColor(string $projectId): ?string
    {
        return $this->settings->find($projectId)?->primaryColor;
    }
}
