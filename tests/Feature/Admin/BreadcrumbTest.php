<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * Asked for by the operator: every console page says where it is and offers the way back up.
 */
final class BreadcrumbTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_page_shows_its_trail_with_links_to_its_parents(): void
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $projectId = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Chantal e-tourisme'), actor: 'service:test', correlationId: null)->project->id;
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: 'TR0001', displayName: 'Agence', email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
        $this->loginAndEnrollMfa();

        // The dashboard is the root: it has no trail.
        $this->get('/admin')->assertOk()->assertDontSee("aria-label=\"Fil d'Ariane\"", false);

        // A global section.
        $this->get('/admin/payments')->assertOk()
            ->assertSee("aria-label=\"Fil d'Ariane\"", false)->assertSee('Tableau de bord')->assertSee('Paiements');

        // A page of a project: Tableau de bord › Projets › <name> › section, parents are links.
        $page = $this->get("/admin/projects/{$projectId}/customers/TR0001")->assertOk();
        $page->assertSee('Chantal e-tourisme')->assertSee('Clients')->assertSee('TR0001');
        $page->assertSee('href="'.url('/admin/projects').'"', false)
            ->assertSee('href="'.url("/admin/projects/{$projectId}").'"', false)
            ->assertSee('href="'.url("/admin/projects/{$projectId}/customers").'"', false);

        // Pages of every family render with a trail and no error.
        foreach (['plans', 'plans/create', 'subscriptions', 'invoices', 'invoices/create', 'receipts', 'indicators', 'usage', 'portal', 'commerce-settings', 'wallets', 'notifications', 'whatsapp-sessions', 'api-keys/create', 'customers/create', 'customers/TR0001/edit'] as $path) {
            $this->get("/admin/projects/{$projectId}/{$path}")->assertOk()->assertSee("aria-label=\"Fil d'Ariane\"", false);
        }

        foreach (['/admin/organizations', '/admin/organizations/create', '/admin/projects', '/admin/projects/create', '/admin/operators', '/admin/audit-log', '/admin/money-exceptions', '/admin/ledger-adjustments', '/admin/reconciliation/new', '/admin/webhook-deliveries/dead', '/admin/portfolio'] as $path) {
            $this->get($path)->assertOk()->assertSee("aria-label=\"Fil d'Ariane\"", false);
        }
    }

    private function loginAndEnrollMfa(): void
    {
        $created = OperatorFactory::create(uniqid('op-', true).'@example.com', OperatorRole::PlatformAdmin);
        $operator = app(OperatorRepository::class)->find($created);
        assert($operator instanceof Operator);

        $this->post('/admin/login', ['email' => $operator->email, 'password' => OperatorFactory::PASSWORD])
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
