<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

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
use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * ADR-0080 §4 — « Test de cloisonnement : un `org_viewer` de l'organisation
 * A ne voit aucun chiffre de l'organisation B, y compris agrégé. »
 *
 * The isolation is checked on the **aggregate**: the portfolio sums the
 * figures of every project the account may see, so a leak would show up
 * in a tile, not only in a row.
 *
 * The account under test is a real member of organisation A, and the
 * permission gate is kept open on purpose (an `org_finance` holds
 * `invoices:write` and `customers:read`): a refusal can then only come
 * from the tenant boundary — 404, never 403 — and never from a role that
 * simply lacks the permission.
 */
final class PortfolioPartitionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_finance_account_of_one_organization_never_sees_the_figures_of_another(): void
    {
        $a = $this->tenant('Alpha', 25_000);
        $b = $this->tenant('Beta', 90_000);

        // B owes 90 000 that A must not see — not in a row, not in a total.
        $this->issueInvoice($b['projectId'], $b['customerRef'], 90_000);

        $this->loginAsMemberOf($a['organizationId'], OrganizationRole::Finance);

        // B's project is "not found" for A: the answer must not confirm
        // that it exists.
        $this->get("/admin/projects/{$b['projectId']}/indicators")->assertNotFound();
        $this->get("/admin/projects/{$b['projectId']}/customers/{$b['customerRef']}")->assertNotFound();

        $portfolio = $this->get('/admin/portfolio')->assertOk();

        $portfolio->assertSee('Projet Alpha')
            ->assertSee('25 000 XAF', false)
            // The customer of A is the only one counted: a leak would
            // make it "2 / 2".
            ->assertSee('1 / 1')
            ->assertDontSee('2 / 2')
            ->assertDontSee('Projet Beta')
            ->assertDontSee('Organisation Beta')
            ->assertDontSee('90 000', false);
    }

    #[Test]
    public function the_figures_of_the_other_organization_are_really_there(): void
    {
        // The control of the test above: the amounts A must not see exist,
        // and B's own finance account sees them. Without this, a broken
        // fixture would make « A does not see B » true for the wrong reason.
        $b = $this->tenant('Beta', 90_000);
        $this->issueInvoice($b['projectId'], $b['customerRef'], 90_000);

        $this->loginAsMemberOf($b['organizationId'], OrganizationRole::Finance);

        $this->get('/admin/portfolio')->assertOk()
            ->assertSee('Projet Beta')
            ->assertSee('90 000 XAF', false);
    }

    #[Test]
    public function a_viewer_account_of_one_organization_reads_no_revenue_at_all(): void
    {
        $a = $this->tenant('Alpha', 25_000);

        $this->loginAsMemberOf($a['organizationId'], OrganizationRole::Viewer);

        // A viewer holds `invoices:read` but not `invoices:write`: the
        // portfolio is what a project earns, and it is closed.
        $this->get('/admin/portfolio')->assertForbidden();
        $this->get("/admin/projects/{$a['projectId']}/indicators")->assertForbidden();
    }

    /**
     * An organization, its project, a customer with a live contract, and the
     * amount that contract bills — the figure a leak would carry.
     *
     * @return array{organizationId: string, projectId: string, customerRef: string}
     */
    private function tenant(string $label, int $price): array
    {
        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Organisation '.$label),
            actor: 'service:test',
            correlationId: null,
        );

        $projectId = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Projet '.$label),
            actor: 'service:test',
            correlationId: null,
        )->project->id;

        $code = strtolower($label);
        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: $projectId, code: $code, name: 'Plan '.$label, description: null,
            visibility: 'public', sortOrder: 0, actor: 'test', correlationId: null,
        ));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId, planCode: $code, priceMinor: $price, currency: 'XAF',
            intervalUnit: 'month', intervalCount: 1, trialDays: 0, graceDays: 7,
            graceAccess: true, features: [], actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: $projectId, planCode: $code, version: $version->version, actor: 'test', correlationId: null,
        ));

        $customerRef = strtoupper($label).'-01';
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: $customerRef, displayName: 'Client '.$label,
            email: null, phone: null, locale: 'fr', metadata: [], expectedVersion: null,
            actor: 'test', correlationId: null,
        ));

        $subscription = app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId, customerRef: $customerRef, planCode: $code, trialDays: 0,
            startAt: null, idempotencyKey: 'sub-'.Str::uuid(), actor: 'test', correlationId: null,
        ));

        // A contract counts in the MRR from the moment it is live.
        DB::table('subscriptions')->where('id', $subscription->id)->update(['status' => 'active']);

        return [
            'organizationId' => $organization->id,
            'projectId' => $projectId,
            'customerRef' => $customerRef,
        ];
    }

    private function issueInvoice(string $projectId, string $customerRef, int $amount): void
    {
        $invoice = app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: $projectId, customerRef: $customerRef,
            lines: [new InvoiceLineInput(description: 'Prestation', quantity: 1, unitAmountMinor: $amount)],
            actor: 'test', correlationId: null,
        ));

        app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: $projectId, invoiceId: $invoice->id, actor: 'test', correlationId: null,
        ));
    }

    /**
     * Signs in as a member of the organization — no platform role — and
     * enrols the second factor, the way a real account does.
     */
    private function loginAsMemberOf(string $organizationId, OrganizationRole $role): void
    {
        $email = 'member-'.Str::lower(Str::random(8)).'@example.com';
        $operatorId = OperatorFactory::create($email, null);

        app(OrganizationMembershipRepository::class)->save(new OrganizationMembership(
            id: (string) Str::uuid(),
            organizationId: $organizationId,
            operatorId: $operatorId,
            role: $role,
            createdAt: new DateTimeImmutable,
        ));

        $this->post('/admin/login', ['email' => $email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $this->get('/admin/mfa/enroll')->getContent();
        $html = is_string($content) ? $content : '';
        if (preg_match('/name="secret"\s+value="([A-Z2-7]+)"/', $html, $secret) !== 1) {
            preg_match('/<code[^>]*>([A-Z2-7]{16,32})<\/code>/', $html, $secret);
        }
        preg_match('/name="challenge_token"\s+value="([^"]+)"/', $html, $challenge);

        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $challenge[1] ?? '',
            'code' => (new Google2FA)->getCurrentOtp($secret[1] ?? ''),
        ])->assertRedirect(route('admin.dashboard'));
    }
}
