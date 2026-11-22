<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\ClientApplication\ClientApplicationEndpoint;
use App\Billing\ClientApplication\ReadsClientApplication;
use Core\Billing\Contracts\GetTenantSchema;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Support\Access\AccessScope;
use Core\Webhook\Contracts\RegisteredWebhookEndpointView;
use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * ADR-0133 — la page *Application cliente* : c'est là que le branchement
 * se fait, et là qu'il se continue.
 *
 * L'opérateur ne tape aucune commande, ne fabrique aucun fichier : il lit
 * l'état, il clique.
 */
final class ClientApplicationConsoleTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function the_page_shows_the_state_of_the_branching(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();
        $this->endpoint($projectId, 'https://app.example.test/webhooks/core');

        $response = $this->get(route('admin.projects.customers.application', $projectId));

        $response->assertOk();
        // The address the Core calls is shown — deduced from the endpoint.
        $response->assertSee('https://app.example.test');
        $response->assertSee('Aucune forme déclarée');
    }

    #[Test]
    public function without_an_endpoint_the_page_says_what_is_missing(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();

        $response = $this->get(route('admin.projects.customers.application', $projectId));

        $response->assertOk();
        $response->assertSee('Enregistrez d\'abord le point d\'entrée des événements de l\'application.');
    }

    #[Test]
    public function the_form_of_the_application_becomes_a_proposal(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();
        $this->endpoint($projectId, 'https://app.example.test/webhooks/core');
        $this->fakeApplication();

        $this->post(route('admin.projects.customers.application.form', $projectId))
            ->assertRedirect();

        // The declaration is a **proposal**: the operator still has to read
        // it and accept it.
        $proposal = app(GetTenantSchema::class)->pending($projectId, AccessScope::platform());
        self::assertNotNull($proposal);
        self::assertSame('code', $proposal->uniqueKey);
    }

    #[Test]
    public function the_clients_of_the_application_are_previewed_then_created(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();
        $this->endpoint($projectId, 'https://app.example.test/webhooks/core');
        $this->fakeApplication([
            ['external_ref' => 'AE0001', 'display_name' => 'Auto-école Soleil', 'email' => 'contact@soleil.test'],
            ['external_ref' => 'AE0002', 'display_name' => 'Sans courriel', 'email' => ''],
        ]);

        // The preview says what would happen — and creates nothing.
        $preview = $this->post(route('admin.projects.customers.application.clients.preview', $projectId));
        $preview->assertOk();
        $preview->assertSee('Aperçu');
        $preview->assertSee('1 à créer');
        self::assertSame(0, DB::table('customers')->where('project_id', $projectId)->count());

        // The creation writes — through the same path as the import.
        $this->post(route('admin.projects.customers.application.clients.import', $projectId))
            ->assertOk();

        self::assertSame(1, DB::table('customers')->where('project_id', $projectId)->count());
    }

    #[Test]
    public function the_operator_can_correct_the_address(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();
        $view = $this->endpoint($projectId, 'https://app.example.test/webhooks/core');

        $this->post(route('admin.projects.customers.application.address', $projectId), [
            'endpoint_id' => $view->id,
            'base_url' => 'https://api.example.test',
        ])->assertRedirect();

        $this->get(route('admin.projects.customers.application', $projectId))
            ->assertOk()
            ->assertSee('https://api.example.test');
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project->id;
    }

    private function endpoint(string $projectId, string $url): RegisteredWebhookEndpointView
    {
        return app(RegisterWebhookEndpoint::class)->register(new RegisterWebhookEndpointCommand(
            projectId: $projectId,
            url: $url,
            events: ['*'],
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function fakeApplication(array $rows = []): void
    {
        $this->app->bind(ReadsClientApplication::class, static fn (): ReadsClientApplication => new FakeApplication($rows));
    }
}

/**
 * The application, and the clients it already has.
 */
final class FakeApplication implements ReadsClientApplication
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(private readonly array $rows) {}

    public function tenantForm(ClientApplicationEndpoint $endpoint): array
    {
        return [
            'unique_key' => 'code',
            'unique_key_pattern' => 'AE[0-9]{4}',
            'fields' => [],
        ];
    }

    public function tenants(ClientApplicationEndpoint $endpoint, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($this->rows, $offset, $perPage);

        return [
            'rows' => $slice,
            'has_more' => ($offset + count($slice)) < count($this->rows),
        ];
    }
}
