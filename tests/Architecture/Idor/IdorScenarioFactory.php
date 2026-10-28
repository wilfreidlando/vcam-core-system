<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\RecordManualReceipt;
use Core\Billing\Contracts\RecordManualReceiptCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OperatorTokenIssuer;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Identity\OperatorRole;
use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerAdjustments;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ProposeLedgerAdjustmentCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Notification\Domain\Model\ChannelSession;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\Domain\Port\ChannelSessionRepository;
use Core\Notification\Domain\Port\NotificationRepository;
use Core\Notification\NotificationStatus;
use Core\Payment\Contracts\RecordMoneyException;
use Core\Payment\Contracts\RecordMoneyExceptionCommand;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Core\Payment\PaymentStatus;
use Core\Support\Access\AccessScope;
use Core\Support\Money\Money;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletRepository;
use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\OperatorFactory;

/**
 * Two organizations, A and B, one project each, and the account under
 * test: **no platform role, `org_owner` of A** — a real membership, so the
 * scope is resolved by the production `ResolveAccessScope`, and the owner
 * role holds every organization permission (`*`): each route's permission
 * is held in A, so a refusal can only come from the isolation boundary,
 * never from the 403 permission gate.
 */
final class IdorScenarioFactory
{
    /**
     * Rows of a tenant a console request could create, change or delete.
     * The audit trail is left out on purpose: recording a refused attempt
     * is not a change to the tenant's resources.
     */
    private const PROJECT_TABLES = [
        'api_keys', 'payment_provider_applications', 'webhook_endpoints', 'webhook_deliveries',
        'notification_channel_sessions', 'notifications', 'payments', 'wallets', 'money_exceptions', 'event_outbox',
        'ledger_adjustments', 'ledger_transactions', 'ledger_accounts',
        'invoices', 'invoice_lines', 'invoice_number_sequences', 'invoice_payments', 'manual_receipts',
    ];

    private const ORGANIZATION_TABLES = ['projects', 'organization_memberships', 'organization_invitations'];

    private function __construct(
        public readonly Tenant $a,
        public readonly Tenant $b,
        public readonly string $attackerToken,
    ) {}

    public static function create(): self
    {
        $a = self::tenant('A');
        $b = self::tenant('B');

        $attacker = self::account('idor-owner-a', $a, OrganizationRole::Owner);

        return new self($a, $b, app(OperatorTokenIssuer::class)->issue($attacker, 'idor-guard'));
    }

    /**
     * Fabricates a resource of `$owner` and addresses it under `$parent`'s
     * project/organization (the owner's by default).
     */
    public function request(ResourceNature $nature, Tenant $owner, ?Tenant $parent = null): ResourceRequest
    {
        $parent ??= $owner;
        $projectId = $parent->project->id;

        switch ($nature) {
            case ResourceNature::Organization:
                return new ResourceRequest(['organizationId' => $owner->organization->id]);

            case ResourceNature::OrganizationMember:
                $email = 'idor-member-'.Str::lower(Str::random(10)).'@example.com';
                $memberId = self::account($email, $owner, OrganizationRole::Viewer, email: $email);

                return new ResourceRequest(['organizationId' => $parent->organization->id, 'operatorId' => $memberId], markers: [$email]);

            case ResourceNature::Project:
                return new ResourceRequest(['projectId' => $owner->project->id]);

            case ResourceNature::ApiKey:
                $issued = app(IssueApiKey::class)->issue(
                    new IssueApiKeyCommand($owner->project->id, ['payments:read'], 'Clé '.$owner->secret),
                    actor: 'test:idor',
                    correlationId: null,
                );

                return new ResourceRequest(['projectId' => $projectId, 'apiKeyId' => $issued->apiKeyId], markers: [$issued->apiKeyId, $issued->prefix]);

            case ResourceNature::ProviderApplication:
                $publicKey = 'pub-'.$owner->secret;
                app(ProviderApplicationRepository::class)->save(new ProviderApplication(
                    projectId: $owner->project->id,
                    providerCode: 'my-coolpay',
                    publicKey: $publicKey,
                    privateKey: 'private-'.$owner->secret,
                ));
                $applicationId = app(ProviderApplicationRepository::class)->findByProjectAndProvider($owner->project->id, 'my-coolpay')->id
                    ?? throw new RuntimeException('The provider application could not be persisted.');

                return new ResourceRequest(['projectId' => $projectId, 'applicationId' => $applicationId], markers: [$applicationId, $publicKey]);

            case ResourceNature::WebhookEndpoint:
                $endpoint = $this->endpoint($owner);

                return new ResourceRequest(['projectId' => $projectId, 'endpointId' => $endpoint['id']], markers: [$endpoint['id'], $endpoint['url']]);

            case ResourceNature::WebhookDelivery:
                $endpoint = $this->endpoint($owner);
                $deliveryId = (string) Str::uuid();
                app(WebhookDeliveryRepository::class)->create(new WebhookDelivery(
                    id: $deliveryId,
                    endpointId: $endpoint['id'],
                    projectId: $owner->project->id,
                    eventId: (string) Str::uuid(),
                    eventType: 'payment.status_changed',
                    payload: ['confidential' => $owner->secret],
                    status: WebhookDeliveryStatus::Dead,
                    attempts: 8,
                    nextRetryAt: null,
                    lastAttemptAt: new DateTimeImmutable,
                    lastError: 'connection timed out',
                    deliveredAt: null,
                ));

                return new ResourceRequest(['delivery' => $deliveryId, 'deliveryId' => $deliveryId], markers: [$deliveryId, $endpoint['url']]);

            case ResourceNature::ChannelSession:
                $sessionRef = 'session-'.Str::lower(Str::random(10));
                app(ChannelSessionRepository::class)->save(new ChannelSession(
                    id: (string) Str::uuid(),
                    projectId: $owner->project->id,
                    channel: 'whatsapp',
                    sessionRef: $sessionRef,
                    createdAt: new DateTimeImmutable,
                ));

                return new ResourceRequest(['projectId' => $projectId, 'sessionRef' => $sessionRef], markers: [$sessionRef]);

            case ResourceNature::Wallet:
                $walletId = (string) Str::uuid();
                $partyId = (string) Str::uuid();
                app(WalletRepository::class)->save(new Wallet($walletId, $owner->project->id, $partyId, 'XAF', 'active'));
                app(WalletBalanceRepository::class)->initialize($walletId, 'XAF');
                app(WalletBalanceRepository::class)->save(new WalletBalance($walletId, Money::ofMinor(2_500, 'XAF'), Money::zero('XAF'), 1));

                return new ResourceRequest(['projectId' => $projectId, 'walletId' => $walletId], markers: [$walletId, $partyId]);

            case ResourceNature::Notification:
                $notificationId = (string) Str::uuid();
                $content = 'Contenu confidentiel '.$owner->secret;
                app(NotificationRepository::class)->create(new Notification(
                    id: $notificationId,
                    projectId: $owner->project->id,
                    channel: 'whatsapp',
                    toPartyRef: (string) Str::uuid(),
                    contentType: 'text',
                    idempotencyKey: (string) Str::uuid(),
                    status: NotificationStatus::Failed,
                    providerCode: null,
                    providerMessageId: null,
                    errorMessage: 'Session not linked',
                    content: ['text' => $content],
                    variables: ['phone_number' => '237690000000'],
                ));

                return new ResourceRequest(['projectId' => $projectId, 'notificationId' => $notificationId], markers: [$notificationId, $content]);

            case ResourceNature::Payment:
                $paymentId = (string) Str::uuid();
                $providerRef = 'ref-'.$owner->secret;
                app(PaymentRepository::class)->create(new Payment(
                    id: $paymentId,
                    projectId: $owner->project->id,
                    channel: 'mtn_momo',
                    providerCode: 'my-coolpay',
                    idempotencyKey: (string) Str::uuid(),
                    providerTransactionRef: $providerRef,
                    amount: 100,
                    currency: 'XAF',
                    status: PaymentStatus::Pending,
                    requiresAction: false,
                    actionHint: null,
                    paymentUrl: null,
                    errorMessage: null,
                    returnUrl: null,
                    walletRecipientExternalRef: null,
                ));

                // Two names for the same payment: the web console addresses
                // it by `payment` and filters on the `project_id` query
                // parameter (`/admin/payments/{payment}`), the JSON plane
                // lists the payments of a project (`/v1/admin/projects/
                // {projectId}/payments`). The request carries both, and
                // the route takes the one it needs.
                return new ResourceRequest(
                    ['payment' => $paymentId, 'projectId' => $projectId],
                    ['project_id' => $projectId],
                    [$paymentId, $providerRef],
                );

            case ResourceNature::MoneyException:
                $summary = 'Exception monétaire '.$owner->secret;
                $exceptionId = app(RecordMoneyException::class)->record(new RecordMoneyExceptionCommand(
                    projectId: $owner->project->id,
                    kind: 'reconciliation_gap',
                    severity: 'high',
                    amountMinor: 100,
                    currency: 'XAF',
                    bookedAmountMinor: 0,
                    paymentId: null,
                    subjectRef: 'subject-'.$owner->secret,
                    summary: $summary,
                )) ?? throw new RuntimeException('The money exception could not be recorded.');

                return new ResourceRequest(['exception' => $exceptionId], markers: [$exceptionId, $summary]);

            case ResourceNature::LedgerAdjustment:
                $reason = 'Correction d\'exploitation '.$owner->secret;
                $reversed = $this->settlement($owner);
                $adjustment = app(LedgerAdjustments::class)->propose(new ProposeLedgerAdjustmentCommand(
                    projectId: $owner->project->id,
                    sourceKind: 'clearing',
                    sourceReference: '',
                    destinationKind: 'revenue',
                    destinationReference: '',
                    amountMinor: 1_000,
                    currency: 'XAF',
                    reason: $reason,
                    proposedBy: (string) Str::uuid(),
                    reversesTransactionId: $reversed,
                ), AccessScope::forProject($owner->project->id));

                return new ResourceRequest(['adjustment' => $adjustment->id], markers: [$adjustment->id, $reason]);

            case ResourceNature::Customer:
                $externalRef = 'idor-customer-'.Str::lower(Str::random(8));
                app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
                    projectId: $owner->project->id,
                    externalRef: $externalRef,
                    displayName: 'Client '.$owner->secret,
                    email: null,
                    phone: null,
                    locale: 'fr',
                    metadata: [],
                    expectedVersion: null,
                    actor: 'test:idor',
                    correlationId: null,
                ));

                return new ResourceRequest(['projectId' => $projectId, 'externalRef' => $externalRef], markers: [$externalRef]);

            case ResourceNature::Plan:
                $code = 'idor-plan-'.Str::lower(Str::random(8));
                app(CreatePlan::class)->create(new CreatePlanCommand(
                    projectId: $owner->project->id,
                    code: $code,
                    name: 'Plan '.$owner->secret,
                    description: null,
                    visibility: 'private',
                    sortOrder: 0,
                    actor: 'test:idor',
                    correlationId: null,
                ));

                // A plan carries its terms in a **version** — the route
                // that publishes one addresses it by its number, so the
                // scenario has to hold a draft to publish.
                $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
                    projectId: $owner->project->id,
                    planCode: $code,
                    priceMinor: 5_000,
                    currency: 'XAF',
                    intervalUnit: 'month',
                    intervalCount: 1,
                    trialDays: 0,
                    graceDays: 7,
                    graceAccess: true,
                    features: [],
                    actor: 'test:idor',
                    correlationId: null,
                ));

                return new ResourceRequest(
                    ['projectId' => $projectId, 'code' => $code, 'version' => (string) $version->version],
                    markers: [$code],
                );

            case ResourceNature::Subscription:
                // A contract needs a customer and a plan whose version is
                // published — the two halves of the subscription.
                $externalRef = 'idor-customer-'.Str::lower(Str::random(8));
                app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
                    projectId: $owner->project->id,
                    externalRef: $externalRef,
                    displayName: 'Client '.$owner->secret,
                    email: null,
                    phone: null,
                    locale: 'fr',
                    metadata: [],
                    expectedVersion: null,
                    actor: 'test:idor',
                    correlationId: null,
                ));

                $code = 'idor-plan-'.Str::lower(Str::random(8));
                app(CreatePlan::class)->create(new CreatePlanCommand(
                    projectId: $owner->project->id,
                    code: $code,
                    name: 'Plan '.$owner->secret,
                    description: null,
                    visibility: 'private',
                    sortOrder: 0,
                    actor: 'test:idor',
                    correlationId: null,
                ));

                $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
                    projectId: $owner->project->id,
                    planCode: $code,
                    priceMinor: 5_000,
                    currency: 'XAF',
                    intervalUnit: 'month',
                    intervalCount: 1,
                    trialDays: 0,
                    graceDays: 7,
                    graceAccess: true,
                    features: [],
                    actor: 'test:idor',
                    correlationId: null,
                ));

                app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
                    projectId: $owner->project->id,
                    planCode: $code,
                    version: $version->version,
                    actor: 'test:idor',
                    correlationId: null,
                ));

                $subscription = app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
                    projectId: $owner->project->id,
                    customerRef: $externalRef,
                    planCode: $code,
                    trialDays: 7,
                    startAt: null,
                    idempotencyKey: 'idor-'.Str::uuid(),
                    actor: 'test:idor',
                    correlationId: null,
                ));

                return new ResourceRequest(
                    ['projectId' => $projectId, 'subscriptionId' => $subscription->id],
                    markers: [$subscription->id, $externalRef, $code],
                );

            case ResourceNature::Invoice:
                // An invoice needs a customer to be billed to; it is
                // created in draft, the state a console gesture starts
                // from (ADR-0073 §1).
                $externalRef = 'idor-customer-'.Str::lower(Str::random(8));
                app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
                    projectId: $owner->project->id,
                    externalRef: $externalRef,
                    displayName: 'Client '.$owner->secret,
                    email: null,
                    phone: null,
                    locale: 'fr',
                    metadata: [],
                    expectedVersion: null,
                    actor: 'test:idor',
                    correlationId: null,
                ));

                $invoice = app(CreateInvoice::class)->create(new CreateInvoiceCommand(
                    projectId: $owner->project->id,
                    customerRef: $externalRef,
                    lines: [new InvoiceLineInput(
                        description: 'Prestation '.$owner->secret,
                        quantity: 1,
                        unitAmountMinor: 5_000,
                    )],
                    actor: 'test:idor',
                    correlationId: null,
                ));

                return new ResourceRequest(
                    ['projectId' => $projectId, 'invoiceId' => $invoice->id],
                    markers: [$invoice->id, $externalRef],
                );

            case ResourceNature::ManualReceipt:
                // An offline receipt (ADR-0073 §4) needs an **open**
                // invoice to sit on: the customer, the invoice and its
                // issuance are made first.
                $externalRef = 'idor-receipt-customer-'.Str::lower(Str::random(8));
                app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
                    projectId: $owner->project->id,
                    externalRef: $externalRef,
                    displayName: 'Client '.$owner->secret,
                    email: null,
                    phone: null,
                    locale: 'fr',
                    metadata: [],
                    expectedVersion: null,
                    actor: 'test:idor',
                    correlationId: null,
                ));

                $invoice = app(CreateInvoice::class)->create(new CreateInvoiceCommand(
                    projectId: $owner->project->id,
                    customerRef: $externalRef,
                    lines: [new InvoiceLineInput(
                        description: 'Prestation '.$owner->secret,
                        quantity: 1,
                        unitAmountMinor: 5_000,
                    )],
                    actor: 'test:idor',
                    correlationId: null,
                ));

                app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
                    projectId: $owner->project->id,
                    invoiceId: $invoice->id,
                    actor: 'test:idor',
                    correlationId: null,
                ));

                $receipt = app(RecordManualReceipt::class)->record(new RecordManualReceiptCommand(
                    projectId: $owner->project->id,
                    invoiceId: $invoice->id,
                    method: 'bank_transfer',
                    reference: 'VIR-IDOR',
                    amountMinor: 1_000,
                    currency: 'XAF',
                    receivedAt: '2026-10-28T08:00:00Z',
                    recordedBy: '00000000-0000-4000-8000-000000000000',
                    actor: 'test:idor',
                    correlationId: null,
                ));

                return new ResourceRequest(
                    ['projectId' => $projectId, 'receiptId' => $receipt->id],
                    markers: [$receipt->id, $externalRef],
                );

            case ResourceNature::Operator:
                $email = 'idor-platform-'.Str::lower(Str::random(10)).'@example.com';
                $operatorId = OperatorFactory::create($email, OperatorRole::PlatformAdmin);

                return new ResourceRequest(['operatorId' => $operatorId], markers: [$email]);

            case ResourceNature::GlobalChannelSession:
                $sessionRef = 'global-'.Str::lower(Str::random(10));
                app(ChannelSessionRepository::class)->save(new ChannelSession(
                    id: (string) Str::uuid(),
                    projectId: null,
                    channel: 'whatsapp',
                    sessionRef: $sessionRef,
                    createdAt: new DateTimeImmutable,
                ));

                return new ResourceRequest(['sessionRef' => $sessionRef], markers: [$sessionRef]);
        }
    }

    /**
     * A fingerprint of every row the tenant owns: compared before and after
     * a request, it proves the request changed nothing of the tenant — a
     * route that answers "not found" but writes on the way out is still an
     * IDOR.
     *
     * @return array<string, string> table => hash of its rows
     */
    public function footprint(Tenant $tenant): array
    {
        $footprint = ['organizations' => self::hashRows(DB::table('organizations')->where('id', $tenant->organization->id)->get()->all())];

        foreach (self::ORGANIZATION_TABLES as $table) {
            $footprint[$table] = self::hashRows(DB::table($table)->where('organization_id', $tenant->organization->id)->get()->all());
        }

        foreach (self::PROJECT_TABLES as $table) {
            $footprint[$table] = self::hashRows(DB::table($table)->where('project_id', $tenant->project->id)->get()->all());
        }

        return $footprint;
    }

    /**
     * @param array<array-key, mixed> $rows
     */
    private static function hashRows(array $rows): string
    {
        $encoded = array_map(static fn (mixed $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($encoded);

        return count($encoded).':'.hash('sha256', implode("\n", $encoded));
    }

    private static function tenant(string $label): Tenant
    {
        $secret = 'org-'.Str::lower($label).'-'.Str::lower(Str::random(12));

        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Organisation '.$label.' '.$secret),
            actor: 'test:idor',
            correlationId: null,
        );

        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Projet '.$label.' '.$secret),
            actor: 'test:idor',
            correlationId: null,
        )->project;

        return new Tenant($organization, $project, $secret);
    }

    /**
     * A console account without platform role, member of the tenant's
     * organization with `$role`.
     */
    private static function account(string $name, Tenant $tenant, OrganizationRole $role, ?string $email = null): string
    {
        $operatorId = OperatorFactory::create($email ?? $name.'-'.Str::lower(Str::random(8)).'@example.com', null);

        app(OrganizationMembershipRepository::class)->save(new OrganizationMembership(
            id: (string) Str::uuid(),
            organizationId: $tenant->organization->id,
            operatorId: $operatorId,
            role: $role,
            createdAt: new DateTimeImmutable,
        ));

        return $operatorId;
    }

    /**
     * A settled transaction of the tenant's project — the transaction an
     * operating correction counter-passes (ADR-0076 §5).
     */
    private function settlement(Tenant $owner): string
    {
        $accounts = app(AccountResolver::class);
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($owner->project->id, 'clearing', '', 'XAF'));
        $revenue = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($owner->project->id, 'revenue', '', 'XAF'));

        $ref = app(LedgerPoster::class)->post(new PostLedgerTransactionCommand(
            projectId: $owner->project->id,
            type: 'payment_settlement',
            externalRef: null,
            idempotencyKey: 'idor:'.Str::uuid(),
            entries: [
                new LedgerEntryInput($clearing->id, Direction::Debit, 1_000, 'XAF'),
                new LedgerEntryInput($revenue->id, Direction::Credit, 1_000, 'XAF'),
            ],
        ));

        return $ref->id;
    }

    /**
     * @return array{id: string, url: string}
     */
    private function endpoint(Tenant $owner): array
    {
        $endpoint = app(RegisterWebhookEndpoint::class)->register(new RegisterWebhookEndpointCommand(
            $owner->project->id,
            'https://'.$owner->secret.'.example.com/hook',
            ['*'],
        ));

        return ['id' => $endpoint->id, 'url' => $endpoint->url];
    }
}
