<?php

declare(strict_types=1);

namespace Core\Portal\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Billing\Contracts\GetInvoice;
use Core\Billing\Contracts\GetInvoiceCommand;
use Core\Billing\Contracts\ListSubscriptions;
use Core\Billing\Contracts\ListSubscriptionsCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Portal\Application\Dto\OpenedPortalSession;
use Core\Portal\Application\Dto\OpenPortalSessionCommand;
use Core\Portal\Application\Support\PortalLinks;
use Core\Portal\Domain\Exception\InvalidPortalFlow;
use Core\Portal\Domain\Exception\PortalCustomerNotFound;
use Core\Portal\Domain\Exception\ReturnUrlNotAllowed;
use Core\Portal\Domain\Model\PortalSession;
use Core\Portal\Domain\Port\PortalSessionRepository;
use Core\Portal\Domain\Port\PortalSettingsRepository;
use Core\Portal\Domain\Port\SecretHasher;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0075 §1 — `POST /v1/portal/sessions`: the server of a project sends
 * one of its customers to the portal.
 *
 * The call is built so that a project integrates in minutes, with no
 * business logic of its own on the commerce side:
 *
 *  - `customer` declares or refreshes the customer (an upsert on its
 *    reference), so the project never has a "sync clients" job;
 *  - `plan_code` subscribes the customer to that plan **only if it has no
 *    live contract yet**, so calling the endpoint on every login is safe;
 *  - the answer is a link, valid ten minutes, usable once.
 *
 * The `return_url` is checked against the project's own list before
 * anything is written (no open redirect), and the call is audited with the
 * address of the calling server.
 */
final readonly class OpenPortalSessionUseCase
{
    private const TERMINAL = ['canceled', 'expired'];

    public function __construct(
        private UnitOfWork $unitOfWork,
        private PortalSettingsRepository $settings,
        private PortalSessionRepository $sessions,
        private SecretHasher $hasher,
        private GetCustomer $customers,
        private UpsertCustomer $upsertCustomer,
        private ListSubscriptions $subscriptions,
        private CreateSubscription $createSubscription,
        private GetInvoice $invoices,
        private AuditLogger $audit,
        private PortalLinks $links,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function open(OpenPortalSessionCommand $command): OpenedPortalSession
    {
        if (! in_array($command->flow, PortalSession::FLOWS, true)) {
            throw InvalidPortalFlow::because('flow must be one of: '.implode(', ', PortalSession::FLOWS).'.');
        }

        if ($command->flow === PortalSession::FLOW_PAY_INVOICE && ($command->invoiceId === null || $command->invoiceId === '')) {
            throw InvalidPortalFlow::because('The pay_invoice flow requires an invoice_id.');
        }

        if ($command->returnUrl !== null && ! $this->settings->find($command->projectId)->allowsReturnUrl($command->returnUrl)) {
            throw ReturnUrlNotAllowed::for($command->returnUrl);
        }

        return $this->unitOfWork->transactional(fn (): OpenedPortalSession => $this->openWithin($command));
    }

    private function openWithin(OpenPortalSessionCommand $command): OpenedPortalSession
    {
        $scope = AccessScope::forProject($command->projectId);
        $now = $this->clock->now();

        $customer = $this->customers->get(new GetCustomerCommand($command->projectId, $command->customerRef), $scope);
        $customerCreated = false;

        if ($command->customer !== null) {
            $customerCreated = $customer === null;
            $given = $command->customer;

            $customer = $this->upsertCustomer->upsert(new UpsertCustomerCommand(
                projectId: $command->projectId,
                externalRef: $command->customerRef,
                displayName: $given['display_name'] ?? $customer->displayName ?? $command->customerRef,
                // A partial call (a login that only knows the name) must not
                // erase what an earlier call declared.
                email: $given['email'] ?? $customer->email ?? null,
                phone: $given['phone'] ?? $customer->phone ?? null,
                locale: $given['locale'] ?? $customer->locale ?? 'fr',
                metadata: $given['metadata'] ?? $customer->metadata ?? [],
                expectedVersion: null,
                actor: 'project:'.$command->projectId,
                correlationId: $command->correlationId,
            ));
        }

        if ($customer === null || $customer->erasedAt !== null) {
            throw PortalCustomerNotFound::forRef($command->customerRef);
        }

        $subscriptionCreated = $command->planCode !== null && $this->ensureSubscription($command, $scope);

        if ($command->flow === PortalSession::FLOW_PAY_INVOICE) {
            $invoice = $this->invoices->get(new GetInvoiceCommand($command->projectId, (string) $command->invoiceId), $scope);

            // Another customer's invoice is "not found", never "forbidden".
            if ($invoice === null || $invoice->customerId !== $customer->id) {
                throw InvalidPortalFlow::because('The invoice does not belong to this customer.');
            }
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = $now->modify('+'.PortalSession::TOKEN_TTL_SECONDS.' seconds');

        $this->sessions->create(new PortalSession(
            id: $this->identifier->generate(),
            projectId: $command->projectId,
            customerId: $customer->id,
            customerRef: $customer->externalRef,
            flow: $command->flow,
            flowRef: $command->invoiceId,
            returnUrl: $command->returnUrl,
            tokenExpiresAt: $expiresAt,
            createdAt: $now,
        ), $this->hasher->hash($token, 'link'), $command->callerIp);

        $this->audit->record(new AuditEntry(
            actor: 'project:'.$command->projectId,
            action: 'portal.session_opened',
            resource: 'customer:'.$customer->id,
            before: null,
            after: null,
            context: [
                'flow' => $command->flow,
                'customer_ref' => $customer->externalRef,
                'caller_ip' => $command->callerIp,
                'customer_created' => $customerCreated,
                'subscription_created' => $subscriptionCreated,
            ],
            correlationId: $command->correlationId,
            occurredAt: $now,
            projectId: $command->projectId,
        ));

        return new OpenedPortalSession(
            $this->links->enter($token),
            $expiresAt->format(DATE_ATOM),
            $customerCreated,
            $subscriptionCreated,
        );
    }

    /**
     * Subscribes the customer to the plan unless a live contract exists
     * on it already — the idempotence that makes the call safe on every login.
     */
    private function ensureSubscription(OpenPortalSessionCommand $command, AccessScope $scope): bool
    {
        $page = $this->subscriptions->list(
            new ListSubscriptionsCommand($command->projectId, $command->customerRef, null, null, 100),
            $scope,
        );

        foreach ($page->items as $existing) {
            if ($existing->planCode === $command->planCode && ! in_array($existing->status, self::TERMINAL, true)) {
                return false;
            }
        }

        $this->createSubscription->create(new CreateSubscriptionCommand(
            projectId: $command->projectId,
            customerRef: $command->customerRef,
            planCode: (string) $command->planCode,
            trialDays: $command->trialDays,
            startAt: null,
            idempotencyKey: 'portal-ensure:'.$command->customerRef.':'.$command->planCode.':'.count($page->items),
            actor: 'project:'.$command->projectId,
            correlationId: $command->correlationId,
        ));

        return true;
    }
}
