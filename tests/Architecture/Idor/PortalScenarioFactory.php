<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

use Core\Billing\Contracts\CancelSubscription;
use Core\Billing\Contracts\CancelSubscriptionCommand;
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
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Portal\Application\Dto\OpenPortalSessionCommand;
use Core\Portal\Application\UseCase\OpenPortalSessionUseCase;
use Core\Portal\Application\UseCase\PortalSessionService;
use Core\Portal\Domain\Model\PortalSettings;
use Core\Portal\Domain\Port\PortalSettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Architecture\Idor\PortalResourceNature as Nature;

/**
 * The scenario of the portal: **one project, two customers of it**, and a
 * third project for the boundary above.
 *
 * The customer — not the project — is the tenant of the portal (ADR-0075):
 * a project hosts many customers, and the portal of a customer shows only
 * what that customer owns. So the neighbour of the customer under test
 * lives in the **same** project: that is the boundary a forgotten
 * `customerId` check would break, and the one the console's project
 * boundary cannot catch.
 *
 * Everything is written inside the transaction the test rolls back: the
 * test database is shared, never refreshed by hand.
 */
final class PortalScenarioFactory
{
    /**
     * The rows of a customer a portal request could read, change or
     * delete. The audit trail is left out on purpose: recording a refused
     * attempt is not a change to the customer's resources.
     */
    private const CUSTOMER_TABLES = ['customers', 'invoices', 'subscriptions', 'portal_sessions'];

    private function __construct(
        /** The customer under test. */
        public readonly PortalTenant $a,
        /** The neighbour: another customer of the SAME project. */
        public readonly PortalTenant $b,
        /** Another project, with its own customer. */
        public readonly PortalTenant $other,
        /** The session cookie of the customer under test. */
        public readonly string $cookie,
        /** The anti-forgery token of that session. */
        public readonly string $csrf,
        /** A second public offer of the project — the one a change of plan moves to. */
        public readonly string $secondPlan,
    ) {}

    /**
     * Builds the scenario for the route under test.
     *
     * Some routes need a state for the request to reach the isolation
     * check instead of being refused before it: « keep renewing » needs a
     * contract that really ends, « renew » an ended contract, and a
     * subscription to an offer a customer without a live contract.
     */
    public static function create(string $route = ''): self
    {
        // The project of the customer under test and of her neighbour.
        $projectId = self::project('P');
        $plan = 'offre-a';
        $secondPlan = 'offre-b';

        self::plan($projectId, $plan);
        self::plan($projectId, $secondPlan);

        // The customer may cancel and change her offer alone: otherwise the
        // portal refuses before it ever looks at the contract, and the
        // isolation case would prove nothing about the contract.
        self::settings($projectId);

        $a = self::customer($projectId, 'A', $plan);
        $b = self::customer($projectId, 'B', $plan);

        // Another project: the boundary above the customer.
        $otherProjectId = self::project('Q');
        self::plan($otherProjectId, 'offre-q');
        $other = self::customer($otherProjectId, 'C', 'offre-q');

        $session = app(PortalSessionService::class);
        $exchanged = $session->exchange(self::openLink($projectId, $a->customerRef));
        $cookie = $exchanged === null ? '' : $exchanged->cookieValue;
        $principal = $session->authenticate($cookie);

        $scenario = new self($a, $b, $other, $cookie, $principal === null ? '' : $session->csrfToken($principal), $secondPlan);

        $scenario->prepare($route);

        return $scenario;
    }

    /**
     * How to reach one of the resources of a customer.
     */
    public function request(Nature $nature, PortalTenant $owner): ResourceRequest
    {
        return match ($nature) {
            Nature::Invoice => new ResourceRequest(['invoiceId' => $owner->invoiceId]),
            Nature::Subscription => new ResourceRequest(['subscriptionId' => $owner->subscriptionId]),
            Nature::Plan => new ResourceRequest(['planCode' => $owner->planCode]),
            Nature::EntryLink => new ResourceRequest(['token' => self::openLink($owner->projectId, $owner->customerRef)]),
            Nature::Project => new ResourceRequest(['projectId' => $owner->projectId]),
        };
    }

    /**
     * A private offer of the project: a negotiated price, never proposed to
     * a customer of the portal — and never reachable by guessing its code.
     */
    public function privateOffer(string $projectId, string $code): void
    {
        self::plan($projectId, $code, 'private');
    }

    /**
     * A fingerprint of every row the customer owns: compared before and
     * after a request, it proves the request changed nothing of hers — a
     * route that answers "not found" but writes on the way out is still an
     * IDOR.
     *
     * @return array<string, string> table => hash of its rows
     */
    public function footprint(PortalTenant $tenant): array
    {
        $footprint = [];

        foreach (self::CUSTOMER_TABLES as $table) {
            $footprint[$table] = self::hashRows(match ($table) {
                'customers' => DB::table('customers')->where('id', $tenant->customerId)->get()->all(),
                'invoices' => DB::table('invoices')->where('customer_id', $tenant->customerId)->get()->all(),
                'subscriptions' => DB::table('subscriptions')->where('customer_id', $tenant->customerId)->get()->all(),
                'portal_sessions' => DB::table('portal_sessions')->where('customer_id', $tenant->customerId)->get()->all(),
            });
        }

        $footprint['invoice_lines'] = self::hashRows(
            DB::table('invoice_lines')
                ->whereIn('invoice_id', DB::table('invoices')->where('customer_id', $tenant->customerId)->pluck('id'))
                ->get()->all(),
        );

        return $footprint;
    }

    /**
     * A one-time access link, as the project hands it to one of its
     * customers: the raw token, never the hash that is stored.
     */
    public static function openLink(string $projectId, string $customerRef): string
    {
        $opened = app(OpenPortalSessionUseCase::class)->open(new OpenPortalSessionCommand(
            projectId: $projectId,
            customerRef: $customerRef,
        ));

        $path = (string) parse_url($opened->url, PHP_URL_PATH);

        return basename($path);
    }

    /**
     * Puts the resources of the customer under test in the state the route
     * under test needs, so the request reaches the isolation check instead
     * of being refused before it — and so the positive control exercises a
     * real gesture.
     */
    private function prepare(string $route): void
    {
        switch ($route) {
            case 'portal.subscriptions.keep':
                // « Keep renewing » only means something on a contract that
                // really ends.
                $this->cancel($this->a, atPeriodEnd: true);

                break;

            case 'portal.subscriptions.renew':
            case 'portal.plans.subscribe':
                // « Renew » needs an ended contract; a subscription to an
                // offer needs a customer without a live contract — and it
                // is what makes the refusal about the offer and not about
                // the caller's own contract.
                $this->cancel($this->a, atPeriodEnd: false);

                break;

            default:
                break;
        }
    }

    /**
     * The contract of a customer ends, or is armed to end at the end of
     * its period (ADR-0072 §7).
     */
    private function cancel(PortalTenant $tenant, bool $atPeriodEnd): void
    {
        app(CancelSubscription::class)->cancel(new CancelSubscriptionCommand(
            projectId: $tenant->projectId,
            subscriptionId: $tenant->subscriptionId,
            atPeriodEnd: $atPeriodEnd,
            reason: 'Scénario IDOR du portail',
            actor: 'test:idor',
            correlationId: null,
        ));
    }

    private static function project(string $label): string
    {
        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Organisation '.$label.' '.Str::lower(Str::random(8))),
            actor: 'test:idor',
            correlationId: null,
        );

        return app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Projet '.$label.' '.Str::lower(Str::random(8))),
            actor: 'test:idor',
            correlationId: null,
        )->project->id;
    }

    /**
     * An offer of the project, with a published version: the catalogue is
     * what a customer may subscribe to alone.
     */
    private static function plan(string $projectId, string $code, string $visibility = 'public'): void
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: $projectId,
            code: $code,
            name: 'Offre '.$code,
            description: null,
            visibility: $visibility,
            sortOrder: 0,
            actor: 'test:idor',
            correlationId: null,
        ));

        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId,
            planCode: $code,
            priceMinor: 25_000,
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
            projectId: $projectId,
            planCode: $code,
            version: $version->version,
            actor: 'test:idor',
            correlationId: null,
        ));
    }

    /**
     * The customer may cancel and change her offer alone: without this the
     * portal refuses before it ever looks at the contract, and the
     * isolation case would prove nothing.
     */
    private static function settings(string $projectId): void
    {
        app(PortalSettingsRepository::class)->save(PortalSettings::validated(
            projectId: $projectId,
            displayName: 'Espace client '.Str::lower(Str::random(6)),
            logoUrl: null,
            primaryColor: '#2563eb',
            supportUrl: null,
            locale: 'fr',
            allowedReturnUrls: [],
            allowSelfCancel: true,
            allowPlanChange: true,
            paymentChannels: [],
        ));
    }

    /**
     * A customer of the project, with an issued invoice and a live
     * contract — the two resources the portal addresses by identifier.
     */
    private static function customer(string $projectId, string $label, string $planCode): PortalTenant
    {
        $secret = 'client-'.Str::lower($label).'-'.Str::lower(Str::random(10));
        $ref = 'CLIENT-'.Str::upper($label).'-'.Str::lower(Str::random(6));

        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId,
            externalRef: $ref,
            displayName: 'Client '.$label.' '.$secret,
            email: null,
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:idor',
            correlationId: null,
        ));

        $draft = app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: $projectId,
            customerRef: $ref,
            lines: [new InvoiceLineInput(
                description: 'Prestation '.$secret,
                quantity: 1,
                unitAmountMinor: 50_000,
            )],
            actor: 'test:idor',
            correlationId: null,
        ));

        $invoice = app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: $projectId,
            invoiceId: $draft->id,
            actor: 'test:idor',
            correlationId: null,
        ));

        $subscription = app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId,
            customerRef: $ref,
            planCode: $planCode,
            trialDays: 7,
            startAt: null,
            idempotencyKey: 'idor-portal-'.Str::uuid(),
            actor: 'test:idor',
            correlationId: null,
        ));

        return new PortalTenant(
            $projectId,
            $invoice->customerId,
            $ref,
            $secret,
            $invoice->id,
            $subscription->id,
            $planCode,
        );
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
}
