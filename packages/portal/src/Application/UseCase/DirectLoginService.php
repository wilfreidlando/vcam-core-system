<?php

declare(strict_types=1);

namespace Core\Portal\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\FindCustomerByContact;
use Core\Billing\Contracts\FindCustomerByContactCommand;
use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\SendTextNotification;
use Core\Notification\Contracts\SendTextNotificationCommand;
use Core\Portal\Application\Dto\ExchangedPortalSession;
use Core\Portal\Application\Dto\LoginAdmission;
use Core\Portal\Domain\Exception\LoginThrottleUnavailable;
use Core\Portal\Domain\Model\PortalLoginCode;
use Core\Portal\Domain\Model\PortalSettings;
use Core\Portal\Domain\Port\LoginThrottle;
use Core\Portal\Domain\Port\PortalLoginCodeRepository;
use Core\Portal\Domain\Port\PortalSettingsRepository;
use Core\Portal\Domain\Port\SecretHasher;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\Privacy\PersonalDataKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ADR-0075 §2, ADR-0100 — the secondary door of the portal: a customer who
 * arrives without going through the project's application types the phone
 * the project knows them by and receives a code on WhatsApp.
 *
 * Three rules shape the class:
 *
 *  - **No enumeration.** `admit()` decides from the input and the budgets
 *    only; `deliver()` — the part that looks the phone up — runs after the
 *    answer has gone out, and does the same work (lookup, code, keyed
 *    hash) whether the phone is known or not. A stranger and a customer
 *    are given the same page, the same status, the same time.
 *  - **Nothing secret at rest.** The code and the phone are never stored
 *    nor logged: only keyed hashes.
 *  - **Fail closed.** If the counters cannot be read, nothing is sent and
 *    nobody is let in.
 *
 * It opens the portal only, never the project's application.
 */
final readonly class DirectLoginService
{
    private const CHANNEL = 'whatsapp';

    public function __construct(
        private PortalSettingsRepository $settings,
        private PortalLoginCodeRepository $codes,
        private SecretHasher $hasher,
        private FindCustomerByContact $contacts,
        private GetCustomer $customers,
        private SendTextNotification $notifications,
        private LoginThrottle $throttle,
        private PortalSessionService $sessions,
        private AuditLogger $audit,
        private LoggerInterface $logger,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    /**
     * The project offers the login by code **and** has said from which
     * WhatsApp session the code leaves.
     */
    public function isOffered(string $projectId): bool
    {
        return ($this->settings->find($projectId) ?? PortalSettings::defaultsFor($projectId))->offersDirectLogin();
    }

    /**
     * Phase one of a request for a code, answered to the browser: is the
     * input acceptable and within its budgets? Never looks the phone up.
     */
    public function admit(string $projectId, string $phone, ?string $ip): LoginAdmission
    {
        if (! $this->isOffered($projectId)) {
            return LoginAdmission::Disabled;
        }

        $normalized = $this->normalize($phone);

        if ($normalized === null) {
            return LoginAdmission::InvalidPhone;
        }

        try {
            return $this->throttle->admitRequest($projectId, $this->contactHash($projectId, $normalized), $ip)
                ? LoginAdmission::Accepted
                : LoginAdmission::Throttled;
        } catch (LoginThrottleUnavailable $e) {
            $this->logger->error('portal.direct_login.throttle_unavailable', ['project_id' => $projectId, 'error' => $e->getMessage()]);

            return LoginAdmission::Unavailable;
        }
    }

    /**
     * Phase two, after the answer: finds the customer and sends the code.
     * Never throws and never tells the browser anything — a failure is a
     * log line (without the phone and without the code).
     */
    public function deliver(string $projectId, string $phone, ?string $ip): void
    {
        try {
            $this->issueAndSend($projectId, $phone, $ip);
        } catch (Throwable $e) {
            $this->logger->error('portal.direct_login.delivery_failed', [
                'project_id' => $projectId,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The budget of the checks of a code, spent per address.
     */
    public function admitVerification(string $projectId, ?string $ip): LoginAdmission
    {
        if (! $this->isOffered($projectId)) {
            return LoginAdmission::Disabled;
        }

        try {
            return $this->throttle->admitVerification($projectId, $ip) ? LoginAdmission::Accepted : LoginAdmission::Throttled;
        } catch (LoginThrottleUnavailable $e) {
            $this->logger->error('portal.direct_login.throttle_unavailable', ['project_id' => $projectId, 'error' => $e->getMessage()]);

            return LoginAdmission::Unavailable;
        }
    }

    /**
     * Checks a code and, if it is the right one, opens the portal session.
     * Every refusal is the same `null`: a wrong code, an expired one, a
     * spent one, one that never existed.
     */
    public function verify(string $projectId, string $phone, string $code, ?string $ip): ?ExchangedPortalSession
    {
        $now = $this->clock->now();
        $normalized = $this->normalize($phone);
        $contactHash = $this->contactHash($projectId, $normalized ?? '');
        $candidate = $this->codeHash($projectId, $contactHash, PortalLoginCode::isWellFormed($code) ? $code : '000000');

        $live = $normalized === null || ! PortalLoginCode::isWellFormed($code)
            ? null
            : $this->codes->findLive($projectId, $contactHash, $now);

        // The attempt is spent before the comparison: concurrent guesses
        // cannot get more than five tries between them.
        if ($live === null || ! $this->codes->registerAttempt($live->id, $now)) {
            return null;
        }

        if (! hash_equals($live->codeHash, $candidate)) {
            $this->logger->info('portal.direct_login.wrong_code', ['project_id' => $projectId, 'attempt' => $live->attempts + 1]);

            return null;
        }

        // Single use: the one caller whose conditional UPDATE finds the code unconsumed.
        if (! $this->codes->consume($live->id, $now)) {
            return null;
        }

        $customer = $this->customers->get(new GetCustomerCommand($projectId, $live->customerRef), AccessScope::forProject($projectId));

        if ($customer === null || $customer->erasedAt !== null || $customer->id !== $live->customerId) {
            return null;
        }

        $session = $this->sessions->openForCustomer($projectId, $customer->id, $customer->externalRef, $ip);

        if ($session === null) {
            return null;
        }

        $this->audit->record(new AuditEntry(
            actor: 'portal:direct_login',
            action: 'portal.session_opened',
            resource: 'customer:'.$customer->id,
            before: null,
            after: null,
            context: ['flow' => 'manage', 'via' => 'direct_login', 'customer_ref' => $customer->externalRef, 'caller_ip' => $ip],
            correlationId: null,
            occurredAt: $now,
            projectId: $projectId,
        ));
        $this->logger->info('portal.direct_login.session_opened', ['project_id' => $projectId, 'customer_id' => $customer->id]);

        return $session;
    }

    private function issueAndSend(string $projectId, string $phone, ?string $ip): void
    {
        $settings = $this->settings->find($projectId);
        $normalized = $this->normalize($phone);

        // A project that never chose the login by code has nothing to send.
        if ($settings === null || ! $settings->offersDirectLogin() || $normalized === null) {
            return;
        }

        $now = $this->clock->now();
        $contactHash = $this->contactHash($projectId, $normalized);

        // The same work for everyone: the lookup, a fresh code and its keyed
        // hash are computed whether the phone is known or not.
        $customer = $this->contacts->find(
            new FindCustomerByContactCommand($projectId, FindCustomerByContactCommand::KIND_PHONE, $normalized),
            AccessScope::forProject($projectId),
        );
        $code = PortalLoginCode::generate();
        $codeHash = $this->codeHash($projectId, $contactHash, $code);
        $lastIssuedAt = $this->codes->lastIssuedAt($projectId, $contactHash);

        if ($customer === null || $customer->phone === null) {
            $this->logger->info('portal.direct_login.no_send', ['project_id' => $projectId, 'reason' => 'unknown_contact']);

            return;
        }

        // One resend a minute at most.
        if ($lastIssuedAt !== null && $lastIssuedAt > $now->modify('-'.PortalLoginCode::RESEND_SECONDS.' seconds')) {
            $this->logger->info('portal.direct_login.no_send', ['project_id' => $projectId, 'reason' => 'resend_too_soon']);

            return;
        }

        // The hourly cap of the project protects the messages it pays for.
        if (! $this->throttle->admitProjectSend($projectId)) {
            $this->logger->warning('portal.direct_login.project_cap_reached', ['project_id' => $projectId]);

            return;
        }

        $id = $this->identifier->generate();
        $this->codes->issue(new PortalLoginCode(
            $id,
            $projectId,
            $customer->id,
            $customer->externalRef,
            $contactHash,
            $codeHash,
            0,
            $now->modify('+'.PortalLoginCode::TTL_SECONDS.' seconds'),
            $now,
        ), $ip, $now);

        $view = $this->notifications->send(new SendTextNotificationCommand(
            projectId: $projectId,
            channel: NotificationChannel::fromString(self::CHANNEL),
            // A correlation reference, never an address (ADR-0041 §5).
            toPartyRef: 'portal-login:'.$customer->id,
            text: sprintf(
                '%s : votre code de connexion est %s. Il est valable %d minutes. Ne le communiquez à personne.',
                $settings->displayName,
                $code,
                intdiv(PortalLoginCode::TTL_SECONDS, 60),
            ),
            variables: [
                'phone_number' => $customer->phone,
                'session' => (string) $settings->directLoginSession,
                // A project that meters its messages names the customer it bills.
                'customer_ref' => $customer->externalRef,
            ],
            idempotencyKey: 'portal-login:'.$id,
        ));

        $this->logger->info('portal.direct_login.code_sent', [
            'project_id' => $projectId,
            'customer_id' => $customer->id,
            'notification_id' => $view->id,
            'status' => $view->status,
        ]);
    }

    /**
     * The phone as the blind index will see it, or null when it cannot be one.
     */
    private function normalize(string $phone): ?string
    {
        $normalized = PersonalDataKind::Phone->normalize($phone);

        return preg_match('/^\+?[0-9]{8,15}\z/', $normalized) === 1 ? $normalized : null;
    }

    private function contactHash(string $projectId, string $normalizedPhone): string
    {
        return $this->hasher->hash($projectId.'|'.$normalizedPhone, 'login-contact');
    }

    private function codeHash(string $projectId, string $contactHash, string $code): string
    {
        return $this->hasher->hash($projectId.'|'.$contactHash.'|'.$code, 'login-code');
    }
}
