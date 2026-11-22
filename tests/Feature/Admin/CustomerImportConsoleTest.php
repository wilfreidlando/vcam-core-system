<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Contracts\GetTenantSchema;
use Core\Billing\Contracts\TenantSchemaFieldView;
use Core\Billing\Contracts\TenantSchemaView;
use Core\Billing\Domain\Model\TenantSchema;
use Core\Billing\Domain\Model\TenantSchemaField;
use Core\Billing\Domain\Model\TenantSchemaFieldType;
use Core\Billing\Domain\Model\TenantSchemaPattern;
use Core\Billing\Domain\Model\TenantSchemaStatus;
use Core\Billing\Domain\Port\TenantSchemaRepository;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Support\Access\AccessScope;
use DateTimeImmutable;
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
 *
 * **Le fichier porte la forme en vigueur** (ADR-0122 §5) : une
 * déclaration acceptée ajoute ses clés au fichier, remplace les champs
 * d'identité du Core qu'elle porte, et ses valeurs sont écrites avec le
 * client.
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

        $csv = "external_ref,display_name,email,phone,localisation\n"
            ."AE0001,Auto-école Soleil,contact@soleil.test,+237690000000,Douala\n"
            ."XYZ,Auto-école Sans motif,contact@sans.test,,\n";

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

        $csv = "external_ref,display_name,email,phone,localisation\n"
            ."XYZ,Auto-école Sans motif,contact@sans.test,,\n";

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

    #[Test]
    public function the_declared_values_are_written_with_the_customer(): void
    {
        $projectId = $this->project();
        $this->bindSchema($this->acceptedSchema($projectId));
        $this->signInToConsole();

        $csv = "external_ref,display_name,email,phone,localisation\n"
            ."AE0001,Auto-école Soleil,contact@soleil.test,+237690000000,Douala\n";

        $this->post(route('admin.projects.customers.import', $projectId), [
            'file' => UploadedFile::fake()->createWithContent('clients.csv', $csv),
        ])->assertOk();

        $customer = DB::table('customers')->where('project_id', $projectId)->where('external_ref', 'AE0001')->first();
        self::assertNotNull($customer);

        // ADR-0130 §1 — the declared value is written with the customer.
        $declared = DB::table('customer_declared_attributes')
            ->where('project_id', $projectId)
            ->where('key', 'localisation')
            ->first();

        self::assertNotNull($declared);
        self::assertSame('Douala', $declared->value);
    }

    #[Test]
    public function a_file_that_does_not_carry_the_form_in_force_is_refused(): void
    {
        $projectId = $this->project();
        $this->bindSchema($this->acceptedSchema($projectId));
        $this->signInToConsole();

        // The file of a project without declaration: the form in force
        // asks for the declared key too.
        $this->from(route('admin.projects.customers.import', $projectId))
            ->post(route('admin.projects.customers.import', $projectId), [
                'file' => UploadedFile::fake()->createWithContent(
                    'clients.csv',
                    "external_ref,display_name,email,phone\nAE0001,Auto-école Soleil,contact@soleil.test,+237690000000\n",
                ),
            ])
            ->assertRedirect(route('admin.projects.customers.import', $projectId))
            ->assertSessionHasErrors('file');

        self::assertSame(0, DB::table('customers')->where('project_id', $projectId)->count());
    }

    #[Test]
    public function a_declared_value_that_is_required_and_empty_refuses_the_line(): void
    {
        $projectId = $this->project();
        $this->bindSchema($this->schemaWithRequiredYear($projectId));
        $this->signInToConsole();

        $csv = "external_ref,display_name,email,phone,annee\n"
            ."AE0001,Auto-école Soleil,contact@soleil.test,+237690000000,\n";

        $response = $this->post(route('admin.projects.customers.import', $projectId), [
            'file' => UploadedFile::fake()->createWithContent('clients.csv', $csv),
        ]);

        $response->assertOk();
        $html = $response->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);
        self::assertStringContainsString('1 ligne refusée', $html);
        self::assertStringContainsString('Année', $html);

        // Nothing was written.
        self::assertSame(0, DB::table('customers')->where('project_id', $projectId)->count());
    }

    #[Test]
    public function a_declared_value_that_does_not_hold_its_type_refuses_the_line(): void
    {
        $projectId = $this->project();
        $this->bindSchema($this->schemaWithRequiredYear($projectId));
        $this->signInToConsole();

        $csv = "external_ref,display_name,email,phone,annee\n"
            ."AE0001,Auto-école Soleil,contact@soleil.test,+237690000000,il y a longtemps\n";

        $response = $this->post(route('admin.projects.customers.import', $projectId), [
            'file' => UploadedFile::fake()->createWithContent('clients.csv', $csv),
        ]);

        $response->assertOk();
        $html = $response->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);
        self::assertStringContainsString('1 ligne refusée', $html);

        self::assertSame(0, DB::table('customers')->where('project_id', $projectId)->count());
    }

    #[Test]
    public function a_declared_key_that_carries_the_name_replaces_the_core_field(): void
    {
        $projectId = $this->project();
        $this->bindSchema($this->schemaWithName($projectId));
        $this->signInToConsole();

        // The file carries no name column: the declared key takes its
        // place (ADR-0122 §5).
        $csv = "external_ref,email,phone,nom,annee\n"
            ."AE0001,contact@soleil.test,+237690000000,Auto-école Soleil,2024\n";

        $this->post(route('admin.projects.customers.import', $projectId), [
            'file' => UploadedFile::fake()->createWithContent('clients.csv', $csv),
        ])->assertOk();

        $customer = DB::table('customers')->where('project_id', $projectId)->where('external_ref', 'AE0001')->first();
        self::assertNotNull($customer);
        self::assertSame('Auto-école Soleil', $customer->display_name);

        // The key that names the client feeds the identity; it does not
        // live twice, in the declared attributes.
        self::assertSame(0, DB::table('customer_declared_attributes')->where('key', 'nom')->count());
        self::assertSame(1, DB::table('customer_declared_attributes')->where('key', 'annee')->count());
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project->id;
    }

    /**
     * The form in force: one declared key, which the file must carry.
     */
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
     * The form in force: a required number, the year of the client.
     */
    private function schemaWithRequiredYear(string $projectId): TenantSchemaView
    {
        return new TenantSchemaView(
            id: '22222222-2222-4222-8222-222222222222',
            projectId: $projectId,
            version: 1,
            status: 'accepted',
            uniqueKey: 'code',
            uniqueKeyPattern: 'AE[0-9]{4,8}',
            fields: [
                new TenantSchemaFieldView(key: 'annee', label: 'Année', type: 'integer', required: true, maxLength: null, source: 'declared.annee'),
            ],
            acceptedAt: '2026-11-11T10:00:00+00:00',
            createdAt: '2026-11-11T09:00:00+00:00',
            updatedAt: '2026-11-11T10:00:00+00:00',
        );
    }

    /**
     * The form in force: the declared key carries the name of the client.
     */
    private function schemaWithName(string $projectId): TenantSchemaView
    {
        return new TenantSchemaView(
            id: '33333333-3333-4333-8333-333333333333',
            projectId: $projectId,
            version: 1,
            status: 'accepted',
            uniqueKey: 'code',
            uniqueKeyPattern: 'AE[0-9]{4,8}',
            fields: [
                new TenantSchemaFieldView(key: 'nom', label: 'Nom', type: 'string', required: true, maxLength: 200, source: 'core_identity.display_name'),
                new TenantSchemaFieldView(key: 'annee', label: 'Année', type: 'integer', required: true, maxLength: null, source: 'declared.annee'),
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
        // ADR-0130 §1 — l'écriture des valeurs déclarées passe par le
        // cas d'usage d'upsert : il lui faut la forme en vigueur.
        $this->bindSchemaRepository($schema);

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

    /**
     * The form in force, as the Core's use case sees it.
     */
    private function bindSchemaRepository(?TenantSchemaView $accepted): void
    {
        $schema = null;

        if ($accepted !== null) {
            $fields = [];

            foreach ($accepted->fields as $field) {
                $fields[] = new TenantSchemaField(
                    key: $field->key,
                    label: $field->label,
                    type: TenantSchemaFieldType::from($field->type),
                    required: $field->required,
                    maxLength: $field->maxLength,
                    source: $field->source,
                );
            }

            $schema = new TenantSchema(
                id: $accepted->id,
                projectId: $accepted->projectId,
                version: $accepted->version,
                status: TenantSchemaStatus::Accepted,
                uniqueKey: $accepted->uniqueKey,
                uniqueKeyPattern: TenantSchemaPattern::of($accepted->uniqueKeyPattern),
                fields: $fields,
                acceptedAt: new DateTimeImmutable($accepted->acceptedAt ?? 'now'),
                createdAt: new DateTimeImmutable($accepted->createdAt),
                updatedAt: new DateTimeImmutable($accepted->updatedAt),
            );
        }

        $this->app->bind(TenantSchemaRepository::class, static fn (): TenantSchemaRepository => new class($schema) implements TenantSchemaRepository
        {
            public function __construct(private readonly ?TenantSchema $schema) {}

            public function findById(string $projectId, string $id): ?TenantSchema
            {
                return $this->schema;
            }

            public function findAccepted(string $projectId): ?TenantSchema
            {
                return $this->schema;
            }

            public function findPending(string $projectId): ?TenantSchema
            {
                return null;
            }

            public function highestVersion(string $projectId): int
            {
                return $this->schema === null ? 0 : $this->schema->version;
            }

            public function save(TenantSchema $schema): void {}
        });
    }
}
