<?php

declare(strict_types=1);

namespace Core\Portal\Application\UseCase;

use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Portal\Application\Dto\ExchangedPortalSession;
use Core\Portal\Application\Dto\PortalBranding;
use Core\Portal\Application\Dto\PortalPrincipal;
use Core\Portal\Domain\Model\PortalSession;
use Core\Portal\Domain\Port\PortalSessionRepository;
use Core\Portal\Domain\Port\PortalSettingsRepository;
use Core\Portal\Domain\Port\SecretHasher;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;

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
        $settings = $this->settings->find($session->projectId);

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
                $settings->logoUrl,
                $settings->primaryColor,
                $settings->supportUrl,
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
        $settings = $this->settings->find($projectId);

        return new PortalBranding($settings->displayName, $settings->logoUrl, $settings->primaryColor, $settings->supportUrl, $settings->locale, $settings->allowSelfCancel, $settings->paymentChannels, $settings->allowPlanChange);
    }
}
