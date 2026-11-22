<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\ClientApplication\ClientApplicationEndpoint;
use App\Billing\ClientApplication\ImportTenantsFromApplication;
use App\Billing\ClientApplication\ReadsClientApplication;
use App\Billing\ImportCustomerRows;
use Core\Billing\Contracts\GetTenantSchema;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Support\Access\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * ADR-0133 §4 — les clients que le Core tire de l'application, et le
 * chemin d'écriture qu'ils empruntent.
 *
 * Le Core **crée** les clients par le chemin de provisionnement qu'il
 * connaît déjà — jamais un cas particulier — et une liste déjà connue est
 * mise à jour, jamais dupliquée.
 */
final class ImportTenantsFromApplicationTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function the_preview_writes_nothing_and_says_what_would_happen(): void
    {
        $projectId = $this->project();
        $puller = $this->puller([
            ['external_ref' => 'AE0001', 'display_name' => 'Auto-école Soleil', 'email' => 'contact@soleil.test', 'phone' => '+237690000000'],
            ['external_ref' => 'AE0002', 'display_name' => 'Auto-école Sans nom', 'email' => 'contact@sans.test'],
        ]);

        $preview = $puller->preview($this->endpoint($projectId), AccessScope::platform(), 'operator:test');

        self::assertSame(2, $preview['lines']);
        self::assertSame(2, $preview['created']);
        self::assertSame(0, $preview['refused']);
        self::assertNull($preview['interrupted']);

        // The preview creates nothing.
        self::assertSame(0, DB::table('customers')->where('project_id', $projectId)->count());
    }

    #[Test]
    public function the_pull_creates_the_clients_and_names_every_refusal(): void
    {
        $projectId = $this->project();
        $puller = $this->puller([
            ['external_ref' => 'AE0001', 'display_name' => 'Auto-école Soleil', 'email' => 'contact@soleil.test', 'phone' => '+237690000000'],
            ['external_ref' => 'AE0002', 'display_name' => 'Sans courriel', 'email' => ''],
            ['external_ref' => 'AE0003', 'display_name' => '', 'email' => 'contact@sansnom.test'],
        ]);

        $report = $puller->import($this->endpoint($projectId), AccessScope::platform(), 'operator:test');

        self::assertSame(3, $report['lines']);
        self::assertSame(1, $report['created']);
        self::assertSame(2, $report['refused']);

        // Every refusal says why.
        $reasons = array_column($report['refusals'], 'reason');
        self::assertContains('L\'adresse électronique est absente.', $reasons);
        self::assertContains('Le nom est vide.', $reasons);

        // The client that could be written was written.
        self::assertSame(1, DB::table('customers')->where('project_id', $projectId)->count());
        $customer = DB::table('customers')->where('project_id', $projectId)->first();
        self::assertNotNull($customer);
        self::assertSame('AE0001', $customer->external_ref);
        self::assertSame('Auto-école Soleil', $customer->display_name);
    }

    #[Test]
    public function a_pulled_client_that_is_already_known_is_updated_and_never_duplicated(): void
    {
        $projectId = $this->project();
        $puller = $this->puller([
            ['external_ref' => 'AE0001', 'display_name' => 'Auto-école Soleil', 'email' => 'contact@soleil.test'],
        ]);

        $puller->import($this->endpoint($projectId), AccessScope::platform(), 'operator:test');

        $again = $puller->import($this->endpoint($projectId), AccessScope::platform(), 'operator:test');

        self::assertSame(0, $again['created']);
        self::assertSame(1, $again['alreadyKnown']);
        self::assertSame(1, DB::table('customers')->where('project_id', $projectId)->count());
    }

    #[Test]
    public function the_pulled_list_and_the_imported_file_share_the_same_write_path(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();

        // The file goes through the import console…
        $csv = "external_ref,display_name,email,phone\n"
            ."AE0001,Auto-école Soleil,contact@soleil.test,+237690000000\n";

        $this->post(route('admin.projects.customers.import', $projectId), [
            'file' => UploadedFile::fake()->createWithContent('clients.csv', $csv),
        ])->assertOk();

        self::assertSame(1, DB::table('customers')->where('project_id', $projectId)->count());

        // …and the pulled list reaches the very same clients: the client is
        // already known, never duplicated.
        $puller = $this->puller([
            ['external_ref' => 'AE0001', 'display_name' => 'Auto-école Soleil', 'email' => 'contact@soleil.test', 'phone' => '+237690000000'],
        ]);

        $report = $puller->import($this->endpoint($projectId), AccessScope::platform(), 'operator:test');

        self::assertSame(0, $report['created']);
        self::assertSame(1, $report['alreadyKnown']);
        self::assertSame(1, DB::table('customers')->where('project_id', $projectId)->count());
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project->id;
    }

    private function endpoint(string $projectId): ClientApplicationEndpoint
    {
        return new ClientApplicationEndpoint($projectId, 'endpoint-1', 'https://app.example.test');
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function puller(array $rows): ImportTenantsFromApplication
    {
        $reader = new FakeClientApplication($rows);

        return new ImportTenantsFromApplication(
            $reader,
            app(GetTenantSchema::class),
            app(ImportCustomerRows::class),
        );
    }
}

/**
 * The application, and the clients it already has.
 */
final class FakeClientApplication implements ReadsClientApplication
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
