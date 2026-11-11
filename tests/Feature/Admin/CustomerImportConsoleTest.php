<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Contracts\GetTenantSchema;
use Core\Billing\Contracts\TenantSchemaFieldView;
use Core\Billing\Contracts\TenantSchemaView;
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
 * ADR-0122 §9 — l'import en lot des clients qu'une application avait déjà.
 *
 * Le fichier exporté passe par le chemin de provisionnement du Core : une
 * ligne = un client. Une ligne dont la référence ne respecte pas le motif
 * en vigueur est **refusée**, et le rapport dit pourquoi. Un import rejoué
 * ne crée rien de plus : la référence est unique au projet.
 */
final class CustomerImportConsoleTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function the_import_creates_the_clients_refuses_the_line_outside_the_pattern_and_creates_nothing_more_on_a_second_run(): void
    {
        $projectId = $this->project();
        $this->bindSchema($this->acceptedSchema($projectId));
        $this->signInToConsole();

        $csv = "external_ref,display_name,email,phone\n"
            ."AE0001,Auto-école Soleil,contact@soleil.test,+237690000000\n"
            ."XYZ,Auto-école Sans motif,contact@sans.test,\n";

        $first = $this->post(route('admin.projects.customers.import', $projectId), [
            'file' => UploadedFile::fake()->createWithContent('clients.csv', $csv),
        ]);

        $first->assertOk();
        $html = $first->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);
        // The report says what happened, line by line.
        self::assertStringContainsString('1 client créé', $html);
        self::assertStringContainsString('1 ligne refusée', $html);
        self::assertStringContainsString('ne respecte pas le format en vigueur', $html);

        // The accepted line became a customer; the refused one did not.
        self::assertNotNull(DB::table('customers')->where('project_id', $projectId)->where('external_ref', 'AE0001')->first());
        self::assertNull(DB::table('customers')->where('project_id', $projectId)->where('external_ref', 'XYZ')->first());

        // A second import of the same file creates nothing more.
        $second = $this->post(route('admin.projects.customers.import', $projectId), [
            'file' => UploadedFile::fake()->createWithContent('clients.csv', $csv),
        ]);

        $second->assertOk();
        $replay = $second->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($replay);
        self::assertStringContainsString('0 client créé', $replay);
        self::assertStringContainsString('1 client déjà connu', $replay);
        self::assertSame(1, DB::table('customers')->where('project_id', $projectId)->count());
    }

    #[Test]
    public function a_file_whose_header_is_not_the_one_of_the_export_is_refused(): void
    {
        $projectId = $this->project();
        $this->bindSchema($this->acceptedSchema($projectId));
        $this->signInToConsole();

        $this->from(route('admin.projects.customers.import', $projectId))
            ->post(route('admin.projects.customers.import', $projectId), [
                'file' => UploadedFile::fake()->createWithContent('clients.csv', "reference,display_name\nAE0001,Auto-école Soleil\n"),
            ])
            ->assertRedirect(route('admin.projects.customers.import', $projectId))
            ->assertSessionHasErrors('file');

        self::assertSame(0, DB::table('customers')->where('project_id', $projectId)->count());
    }

    #[Test]
    public function the_import_never_writes_the_value_of_a_refused_line_in_the_audit(): void
    {
        $projectId = $this->project();
        $this->bindSchema($this->acceptedSchema($projectId));
        $this->signInToConsole();

        $csv = "external_ref,display_name,email,phone\n"
            ."XYZ,Auto-école Sans motif,contact@sans.test,\n";

        $this->post(route('admin.projects.customers.import', $projectId), [
            'file' => UploadedFile::fake()->createWithContent('clients.csv', $csv),
        ])->assertOk();

        $entry = DB::table('audit_log')->where('action', 'billing.customers.imported')->first();
        self::assertNotNull($entry);
        self::assertIsString($entry->after);
        self::assertIsString($entry->context);
        self::assertStringNotContainsString('contact@sans.test', $entry->after);
        self::assertStringNotContainsString('Sans motif', $entry->after.$entry->context);
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project->id;
    }

    private function acceptedSchema(string $projectId): TenantSchemaView
    {
        return new TenantSchemaView(
            id: '11111111-1111-4111-8111-111111111111',
            projectId: $projectId,
            version: 1,
            status: 'accepted',
            uniqueKey: 'code',
            uniqueKeyPattern: 'AE[0-9]{4,8}',
            fields: [
                new TenantSchemaFieldView(key: 'localisation', label: 'Localisation', type: 'string', required: false, maxLength: 120, source: 'declared.localisation'),
            ],
            acceptedAt: '2026-11-11T10:00:00+00:00',
            createdAt: '2026-11-11T09:00:00+00:00',
            updatedAt: '2026-11-11T10:00:00+00:00',
        );
    }

    /**
     * The form in force: the references must respect its pattern.
     */
    private function bindSchema(TenantSchemaView $schema): void
    {
        $this->app->bind(GetTenantSchema::class, static fn (): GetTenantSchema => new class($schema) implements GetTenantSchema
        {
            public function __construct(private readonly ?TenantSchemaView $schema) {}

            public function accepted(string $projectId, AccessScope $scope): ?TenantSchemaView
            {
                return $this->schema;
            }

            public function acceptsReference(string $projectId, string $reference, AccessScope $scope): bool
            {
                if ($this->schema === null) {
                    return true;
                }

                return preg_match('/\A(?:'.$this->schema->uniqueKeyPattern.')\z/', $reference) === 1;
            }

            /**
             * The declaration waiting to be read (ADR-0122 §2) — the
             * import console does not read it.
             */
            public function pending(string $projectId, AccessScope $scope): ?TenantSchemaView
            {
                return null;
            }
        });
    }
}
