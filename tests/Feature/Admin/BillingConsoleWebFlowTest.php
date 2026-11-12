<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OperatorTokenIssuer;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Identity\Presentation\Http\Middleware\ResolveOperatorMiddleware;
use DateTimeImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * The billing console (ADR-0092 §4) — the web mirror of the public API:
 * the customers, the plan catalogue and the subscription contracts of a
 * project, driven by a real operator's session.
 *
 * The IDOR harness proves the console refuses another organization's
 * resources; this proves the pages it *does* open work. A page that
 * fatals on its own tenant would pass every isolation case.
 */
final class BillingConsoleWebFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_operator_manages_the_commerce_of_a_project_from_the_console(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        [$projectId, $token] = $this->tenant();

        $this->withSession([ResolveOperatorMiddleware::SESSION_KEY => $token]);

        // The three pages of the commerce plane open on the operator's
        // own project.
        $this->get(route('admin.projects.customers.index', $projectId))->assertOk();
        $this->get(route('admin.projects.plans.index', $projectId))->assertOk();
        $this->get(route('admin.projects.subscriptions.index', $projectId))->assertOk();

        // The project page offers the three entries (ADR-0092 §4).
        $this->get(route('admin.projects.show', $projectId))
            ->assertOk()
            ->assertSee('Facturation')
            ->assertSee('Clients de ce projet');

        // A customer is declared from the console...
        $created = $this->post(route('admin.projects.customers.store', $projectId), [
            'external_ref' => 'acme',
            'display_name' => 'ACME',
            'email' => 'contact@acme.test',
            'phone' => '+237690000000',
            'locale' => 'fr',
        ]);
        $created->assertRedirect(route('admin.projects.customers.index', $projectId));
        $created->assertSessionHas('status');

        // ...and its contact reaches the disk in clear (ADR-0123): the
        // Core keeps no key of its own for the data of the clients.
        $row = DB::table('customers')->where('external_ref', 'acme')->first();
        self::assertNotNull($row);
        self::assertSame('contact@acme.test', $row->email);
        self::assertSame('+237690000000', $row->phone);

        // ADR-0083 §3 / ADR-0093 §6 — the console never shows a
        // personal value in clear: the page carries the **masked**
        // value, and the field starts empty.
        $edit = $this->get(route('admin.projects.customers.edit', [$projectId, 'acme']));
        $edit->assertOk();
        $edit->assertDontSee('contact@acme.test');
        $edit->assertDontSee('+237690000000');
        $edit->assertSee('c***@a***.test');

        // The catalogue: a plan, a version, a publication.
        $this->post(route('admin.projects.plans.store', $projectId), [
            'code' => 'mensuel',
            'name' => 'Abonnement mensuel',
            'visibility' => 'public',
        ])->assertRedirect(route('admin.projects.plans.index', $projectId));

        $this->post(route('admin.projects.plans.versions.store', [$projectId, 'mensuel']), [
            'price_minor' => 5000,
            'currency' => 'XAF',
            'interval_unit' => 'month',
            'interval_count' => 1,
            'trial_days' => 7,
        ])->assertRedirect(route('admin.projects.plans.index', $projectId));

        $this->post(route('admin.projects.plans.versions.publish.web', [$projectId, 'mensuel', 1]))
            ->assertRedirect(route('admin.projects.plans.index', $projectId));

        // A contract signed on the published version, and its page.
        $subscriptionId = app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId,
            customerRef: 'acme',
            planCode: 'mensuel',
            trialDays: null,
            startAt: null,
            idempotencyKey: 'console-'.Str::uuid(),
            actor: 'test:console',
            correlationId: null,
        ))->id;

        $this->get(route('admin.projects.subscriptions.show', [$projectId, $subscriptionId]))->assertOk();

        // The console's acts are audited — the `billing` module may not
        // depend on `audit` (ADR-0092 §3), so the console audits them.
        $audit = DB::table('audit_log')->where('action', 'like', 'billing.%')->get();
        self::assertGreaterThan(0, $audit->count());
    }

    /**
     * An organization, its project, and an account that owns it.
     *
     * @return array{0: string, 1: string} the project id and the operator's token
     */
    private function tenant(): array
    {
        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Organisation console'),
            actor: 'test:console',
            correlationId: null,
        );

        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Projet console'),
            actor: 'test:console',
            correlationId: null,
        )->project;

        $operatorId = OperatorFactory::create('console-'.Str::lower(Str::random(8)).'@example.com', null);

        app(OrganizationMembershipRepository::class)->save(new OrganizationMembership(
            id: (string) Str::uuid(),
            organizationId: $organization->id,
            operatorId: $operatorId,
            role: OrganizationRole::Owner,
            createdAt: new DateTimeImmutable,
        ));

        return [$project->id, app(OperatorTokenIssuer::class)->issue($operatorId, 'console-test')];
    }
}
