<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Contracts\AcceptTenantSchema;
use Core\Billing\Contracts\AcceptTenantSchemaCommand;
use Core\Billing\Contracts\GetCustomerDeclaredAttributes;
use Core\Billing\Contracts\GetTenantSchema;
use Core\Billing\Contracts\RejectTenantSchema;
use Core\Billing\Contracts\RejectTenantSchemaCommand;
use Core\Billing\Contracts\SetCustomerDeclaredAttributes;
use Core\Billing\Contracts\TenantSchemaFieldView;
use Core\Billing\Contracts\TenantSchemaView;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Support\Access\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeDeclaredAttributes;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * ADR-0122 — the declared form, in the console.
 *
 * The application declares the shape of its client; the operator reads the
 * declaration, accepts it or refuses it; the customer form then shows one
 * field per declared key, and refuses a reference that does not respect the
 * pattern in force.
 *
 * The schema ports are replaced by doubles here: what is proved is the
 * **console** — the fields it renders, the gesture it makes, the error it
 * puts under the right field — not the persistence of the declaration.
 */
final class TenantSchemaConsoleTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function the_form_shows_one_field_per_declared_key_and_no_text_area_to_parse(): void
    {
        $projectId = $this->project();
        $this->bindSchema($this->acceptedSchema($projectId));
        $this->signInToConsole();

        $html = $this->get(route('admin.projects.customers.new', $projectId))->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertIsString($html);
        // One field per declared key, in the order of the declaration.
        self::assertStringContainsString('name="attributes[localisation]"', $html);
        self::assertStringContainsString('name="attributes[annee]"', $html);
        self::assertStringContainsString('Localisation', $html);
        self::assertStringContainsString('Année de création', $html);
        // The marker of an optional field — and the star of a mandatory one.
        self::assertMatchesRegularExpression('/Localisation\s*<span[^>]*>\(facultatif\)<\/span>/s', $html);
        self::assertMatchesRegularExpression('/Année de création\s*<span aria-hidden="true"/s', $html);
        // The pattern is written before the operator types.
        self::assertStringContainsString('AE[0-9]{4,8}', $html);
        self::assertStringContainsString('AE suivi de 4 à 8 chiffres', $html);
        // No area to parse: the form is made of fields.
        self::assertStringNotContainsString('<textarea', $html);
        self::assertStringNotContainsString('JSON', $html);
    }

    #[Test]
    public function a_project_without_a_declaration_shows_the_core_identity_only(): void
    {
        $projectId = $this->project();
        $this->bindSchema(null);
        $this->signInToConsole();

        $html = $this->get(route('admin.projects.customers.new', $projectId))->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertIsString($html);
        self::assertStringContainsString('Cette application n\'a déclaré aucun champ supplémentaire.', $html);
        self::assertStringNotContainsString('name="attributes[', $html);
    }

    #[Test]
    public function a_reference_outside_the_pattern_is_refused_under_its_own_field(): void
    {
        $projectId = $this->project();
        $this->bindSchema($this->acceptedSchema($projectId));
        $this->signInToConsole();

        $post = $this->from(route('admin.projects.customers.new', $projectId))
            ->post(route('admin.projects.customers.store', $projectId), [
                'external_ref' => 'XYZ',
                'display_name' => 'Auto-école Soleil',
            ]);

        $post->assertRedirect(route('admin.projects.customers.new', $projectId));

        // The refusal is carried by the session — the operator comes back to
        // the form and finds the reason under the field he filled in.
        //
        // NOTE — the session is read **here**, on the store itself, and not
        // through `$post->assertSessionHasErrors()`: that helper calls
        // `TestResponse::session()`, which restarts a session that the
        // request has just closed (`Store::save()` resets `started`), and
        // restarting it **reloads the session from the handler** — where the
        // error bag has already been aged away. The refusal would be lost
        // before the operator ever sees the form.
        $errors = $this->app->make('session.store')->get('errors');
        self::assertIsArray($errors);
        self::assertArrayHasKey('default', $errors);
        /** @var array{format: string, messages: array<string, list<string>>} $default */
        $default = $errors['default'];
        self::assertArrayHasKey('external_ref', $default['messages']);
        self::assertStringContainsString(
            'ne respecte pas le format en vigueur',
            $default['messages']['external_ref'][0],
        );

        // The error is under the reference field, and the page says why.
        $html = $this->get(route('admin.projects.customers.new', $projectId))->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);
        self::assertStringContainsString('id="field-external_ref-error"', $html);
        self::assertStringContainsString('ne respecte pas le format en vigueur', $html);

        // Nothing was written.
        self::assertSame(0, DB::table('customers')->where('project_id', $projectId)->count());
    }

    #[Test]
    public function a_declared_key_that_carries_the_contact_shows_only_one_field_for_it(): void
    {
        $projectId = $this->project();
        $this->bindSchema($this->acceptedSchema($projectId, withContact: true));
        $this->signInToConsole();

        $html = $this->get(route('admin.projects.customers.new', $projectId))->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertIsString($html);
        // The declared key replaces the Core's own field: one input, not two.
        self::assertStringContainsString('name="attributes[courriel]"', $html);
        self::assertStringNotContainsString('name="email"', $html);
    }

    #[Test]
    public function a_key_that_asks_for_a_core_field_is_written_in_the_identity_and_not_in_the_declared_attributes(): void
    {
        $projectId = $this->project();
        $this->bindSchema($this->acceptedSchema($projectId, withContact: true));
        $declared = $this->bindDeclaredAttributes();
        $this->signInToConsole();

        $this->post(route('admin.projects.customers.store', $projectId), [
            'external_ref' => 'AE0001',
            'display_name' => 'Auto-école Soleil',
            'attributes' => ['courriel' => 'contact@exemple.test', 'annee' => '2019'],
        ])->assertRedirect(route('admin.projects.customers.index', $projectId));

        // The value the application asked for goes into the Core's
        // identity — which the Core keeps in clear (ADR-0123) — and NOT
        // into the declared attributes, which are the application's own
        // values. The year, which the application declared for itself, is
        // a declared value.
        self::assertSame(['annee' => '2019'], $declared->last);
        self::assertArrayNotHasKey('courriel', $declared->last);

        $stored = DB::table('customer_declared_attributes')->pluck('value')->all();
        self::assertNotContains('contact@exemple.test', $stored);

        $customer = DB::table('customers')->where('external_ref', 'AE0001')->first();
        self::assertNotNull($customer);
        // The identity holds the address itself: the Core encrypts
        // nothing of the client any more (ADR-0123).
        self::assertSame('contact@exemple.test', $customer->email);
    }

    #[Test]
    public function the_acceptance_page_lists_the_proposal_and_accepting_puts_the_form_in_force(): void
    {
        $projectId = $this->project();
        $proposal = $this->proposal($projectId);
        $this->bindSchema(null, $proposal);
        $this->signInToConsole();

        // The proposal is a readable list — never a text area.
        $html = $this->get(route('admin.projects.customers.schema', $projectId))->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);
        self::assertStringContainsString('Localisation', $html);
        self::assertStringContainsString('Texte court', $html);
        self::assertStringContainsString('Nombre entier', $html);
        self::assertStringContainsString('AE[0-9]{4,8}', $html);
        self::assertStringNotContainsString('<textarea', $html);

        // The operator accepts it.
        $this->post(route('admin.projects.customers.schema.accept', [$projectId, $proposal->id]))
            ->assertRedirect(route('admin.projects.customers.schema', $projectId))
            ->assertSessionHas('status');

        // The gesture is audited — and the audit carries no value.
        $entry = DB::table('audit_log')->where('action', 'billing.tenant_schema.accepted')->first();
        self::assertNotNull($entry);
        self::assertIsString($entry->actor);
        self::assertStringStartsWith('operator:', $entry->actor);
        self::assertIsString($entry->after);
        self::assertStringNotContainsString('Localisation', $entry->after);
    }

    #[Test]
    public function the_acceptance_page_says_when_there_is_nothing_to_read(): void
    {
        $projectId = $this->project();
        $this->bindSchema(null);
        $this->signInToConsole();

        $html = $this->get(route('admin.projects.customers.schema', $projectId))->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertIsString($html);
        self::assertStringContainsString('Rien à décider pour l\'instant', $html);
        self::assertStringContainsString(route('admin.projects.customers.index', $projectId), $html);
    }

    #[Test]
    public function refusing_the_declaration_leaves_the_previous_form_in_force(): void
    {
        $projectId = $this->project();
        $proposal = $this->proposal($projectId);
        $this->bindSchema(null, $proposal);
        $this->signInToConsole();

        $this->post(route('admin.projects.customers.schema.reject', [$projectId, $proposal->id]))
            ->assertRedirect(route('admin.projects.customers.schema', $projectId))
            ->assertSessionHas('status');

        self::assertNotNull(DB::table('audit_log')->where('action', 'billing.tenant_schema.rejected')->first());
    }

    #[Test]
    public function the_declared_values_are_written_with_the_client(): void
    {
        $projectId = $this->project();
        $this->bindSchema($this->acceptedSchema($projectId));
        $declared = $this->bindDeclaredAttributes();
        $this->signInToConsole();

        $this->post(route('admin.projects.customers.store', $projectId), [
            'external_ref' => 'AE0001',
            'display_name' => 'Auto-école Soleil',
            'attributes' => ['localisation' => 'Douala', 'annee' => '2019'],
        ])->assertRedirect(route('admin.projects.customers.index', $projectId));

        self::assertSame(['localisation' => 'Douala', 'annee' => '2019'], $declared->last);
        self::assertCount(1, $declared->stored);
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project->id;
    }

    private function acceptedSchema(string $projectId, bool $withContact = false): TenantSchemaView
    {
        $fields = [
            new TenantSchemaFieldView(key: 'localisation', label: 'Localisation', type: 'string', required: false, maxLength: 120, source: 'declared.localisation'),
            new TenantSchemaFieldView(key: 'annee', label: 'Année de création', type: 'integer', required: true, maxLength: null, source: 'declared.annee'),
        ];

        if ($withContact) {
            $fields[] = new TenantSchemaFieldView(key: 'courriel', label: 'Courriel', type: 'string', required: false, maxLength: 255, source: 'core_identity.email');
        }

        return new TenantSchemaView(
            id: '11111111-1111-4111-8111-111111111111',
            projectId: $projectId,
            version: 1,
            status: 'accepted',
            uniqueKey: 'code',
            uniqueKeyPattern: 'AE[0-9]{4,8}',
            fields: $fields,
            acceptedAt: '2026-11-11T10:00:00+00:00',
            createdAt: '2026-11-11T09:00:00+00:00',
            updatedAt: '2026-11-11T10:00:00+00:00',
        );
    }

    private function proposal(string $projectId): TenantSchemaView
    {
        return new TenantSchemaView(
            id: '22222222-2222-4222-8222-222222222222',
            projectId: $projectId,
            version: 2,
            status: 'pending',
            uniqueKey: 'code',
            uniqueKeyPattern: 'AE[0-9]{4,8}',
            fields: [
                new TenantSchemaFieldView(key: 'localisation', label: 'Localisation', type: 'string', required: false, maxLength: 120, source: 'declared.localisation'),
                new TenantSchemaFieldView(key: 'annee', label: 'Année de création', type: 'integer', required: true, maxLength: null, source: 'declared.annee'),
            ],
            acceptedAt: null,
            createdAt: '2026-11-11T11:00:00+00:00',
            updatedAt: '2026-11-11T11:00:00+00:00',
        );
    }

    /**
     * The schema ports are doubled: what the console does with what it
     * reads is what is proved here.
     */
    private function bindSchema(?TenantSchemaView $accepted, ?TenantSchemaView $proposal = null): void
    {
        $this->app->bind(GetTenantSchema::class, static fn (): GetTenantSchema => new class($accepted, $proposal) implements GetTenantSchema
        {
            public function __construct(
                private readonly ?TenantSchemaView $accepted,
                private readonly ?TenantSchemaView $proposal,
            ) {}

            public function accepted(string $projectId, AccessScope $scope): ?TenantSchemaView
            {
                return $this->accepted;
            }

            public function acceptsReference(string $projectId, string $reference, AccessScope $scope): bool
            {
                if ($this->accepted === null) {
                    return true;
                }

                return preg_match('/\A(?:'.$this->accepted->uniqueKeyPattern.')\z/', $reference) === 1;
            }

            /**
             * The declaration waiting to be read (ADR-0122 §2).
             */
            public function pending(string $projectId, AccessScope $scope): ?TenantSchemaView
            {
                return $this->proposal;
            }
        });

        $this->app->bind(AcceptTenantSchema::class, static fn (): AcceptTenantSchema => new class implements AcceptTenantSchema
        {
            /** @var list<AcceptTenantSchemaCommand> */
            public array $accepted = [];

            public function accept(AcceptTenantSchemaCommand $command): TenantSchemaView
            {
                $this->accepted[] = $command;

                return new TenantSchemaView(
                    id: $command->schemaId,
                    projectId: $command->projectId,
                    version: 1,
                    status: 'accepted',
                    uniqueKey: 'code',
                    uniqueKeyPattern: 'AE[0-9]{4,8}',
                    fields: [],
                    acceptedAt: '2026-11-11T12:00:00+00:00',
                    createdAt: '2026-11-11T11:00:00+00:00',
                    updatedAt: '2026-11-11T12:00:00+00:00',
                );
            }
        });

        $this->app->bind(RejectTenantSchema::class, static fn (): RejectTenantSchema => new class implements RejectTenantSchema
        {
            /** @var list<RejectTenantSchemaCommand> */
            public array $rejected = [];

            public function reject(RejectTenantSchemaCommand $command): TenantSchemaView
            {
                $this->rejected[] = $command;

                return new TenantSchemaView(
                    id: $command->schemaId,
                    projectId: $command->projectId,
                    version: 1,
                    status: 'rejected',
                    uniqueKey: 'code',
                    uniqueKeyPattern: 'AE[0-9]{4,8}',
                    fields: [],
                    acceptedAt: null,
                    createdAt: '2026-11-11T11:00:00+00:00',
                    updatedAt: '2026-11-11T12:00:00+00:00',
                );
            }
        });
    }

    /**
     * The declared values, kept in memory: the console writes them, the
     * test reads them back.
     */
    private function bindDeclaredAttributes(): FakeDeclaredAttributes
    {
        $declared = new FakeDeclaredAttributes;

        $this->app->instance(SetCustomerDeclaredAttributes::class, $declared);
        $this->app->instance(GetCustomerDeclaredAttributes::class, $declared);

        return $declared;
    }
}
