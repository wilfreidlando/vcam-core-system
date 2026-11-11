<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

use Core\Billing\Contracts\AcceptTenantSchema;
use Core\Billing\Contracts\AcceptTenantSchemaCommand;
use Core\Billing\Contracts\CustomerView;
use Core\Billing\Contracts\GetCustomerDeclaredAttributes;
use Core\Billing\Contracts\GetTenantSchema;
use Core\Billing\Contracts\ProposeTenantSchema;
use Core\Billing\Contracts\ProposeTenantSchemaCommand;
use Core\Billing\Contracts\RejectTenantSchema;
use Core\Billing\Contracts\RejectTenantSchemaCommand;
use Core\Billing\Contracts\SetCustomerDeclaredAttributes;
use Core\Billing\Contracts\SetCustomerDeclaredAttributesCommand;
use Core\Billing\Contracts\TenantSchemaView;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Domain\Exception\CustomerDeclaredAttributeRefused;
use Core\Billing\Domain\Exception\InvalidTenantSchema;
use Core\Support\Access\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0122 — the declared form of a tenant, against a real PostgreSQL.
 *
 * The rules these tests pin are the ones the code could otherwise break
 * quietly:
 *
 *  - a proposal is a **version**, and it waits for the operator: it is
 *    not the form in force (ADR-0122 §2) ;
 *  - **one** declaration is accepted per project: accepting a new one
 *    replaces the previous (ADR-0122 §2) ;
 *  - a refused declaration never becomes the form in force (ADR-0122 §2) ;
 *  - the form is bounded — 32 fields, 4 KiB — and it may not redeclare a
 *    source of the Core, nor a pattern that could authorise a reference
 *    outside the Core's alphabet (ADR-0122 §1, §5, §6) ;
 *  - the values a tenant declares are checked against the form in force
 *    (ADR-0122 §2, §5).
 */
final class TenantSchemaFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '11111111-1111-4111-8111-111111111111';

    #[Test]
    public function a_proposal_waits_for_the_operator(): void
    {
        $proposed = $this->propose();

        self::assertSame('pending', $proposed->status);
        self::assertSame(1, $proposed->version);
        self::assertSame('code_etablissement', $proposed->uniqueKey);
        self::assertSame('AE-[0-9]{4}', $proposed->uniqueKeyPattern);
        self::assertNull($proposed->acceptedAt);

        // Nothing is in force yet: the Core never renders a form nobody
        // has read (ADR-0122 §2).
        self::assertNull($this->accepted());
    }

    #[Test]
    public function the_accepted_declaration_is_the_one_in_force(): void
    {
        $proposed = $this->propose();

        $accepted = app(AcceptTenantSchema::class)->accept(new AcceptTenantSchemaCommand(
            projectId: self::PROJECT,
            schemaId: $proposed->id,
            actor: 'operator:test',
            correlationId: null,
        ));

        self::assertSame('accepted', $accepted->status);
        self::assertNotNull($accepted->acceptedAt);

        $inForce = $this->accepted();
        self::assertNotNull($inForce);
        self::assertSame($proposed->id, $inForce->id);
        self::assertSame('code_etablissement', $inForce->uniqueKey);
    }

    #[Test]
    public function only_one_declaration_is_accepted_per_project(): void
    {
        $first = $this->propose();
        app(AcceptTenantSchema::class)->accept(new AcceptTenantSchemaCommand(
            projectId: self::PROJECT,
            schemaId: $first->id,
            actor: 'operator:test',
            correlationId: null,
        ));

        // A second proposal is a second version — the form in force is
        // still the first one until the operator reads the second.
        $second = $this->propose();
        self::assertSame(2, $second->version);

        $beforeAcceptance = $this->accepted();
        self::assertNotNull($beforeAcceptance);
        self::assertSame($first->id, $beforeAcceptance->id);

        app(AcceptTenantSchema::class)->accept(new AcceptTenantSchemaCommand(
            projectId: self::PROJECT,
            schemaId: $second->id,
            actor: 'operator:test',
            correlationId: null,
        ));

        $afterAcceptance = $this->accepted();
        self::assertNotNull($afterAcceptance);
        self::assertSame($second->id, $afterAcceptance->id);

        self::assertSame(
            1,
            DB::table('project_tenant_schemas')
                ->where('project_id', self::PROJECT)
                ->where('status', 'accepted')
                ->count(),
        );
        self::assertSame(
            1,
            DB::table('project_tenant_schemas')
                ->where('project_id', self::PROJECT)
                ->where('status', 'superseded')
                ->count(),
        );
    }

    #[Test]
    public function a_refused_declaration_is_never_in_force(): void
    {
        $proposed = $this->propose();

        $refused = app(RejectTenantSchema::class)->reject(new RejectTenantSchemaCommand(
            projectId: self::PROJECT,
            schemaId: $proposed->id,
            actor: 'operator:test',
            correlationId: null,
        ));

        self::assertSame('superseded', $refused->status);
        self::assertNull($refused->acceptedAt);
        self::assertNull($this->accepted());
    }

    #[Test]
    public function a_declaration_may_not_redeclare_a_source_of_the_core(): void
    {
        $this->expectException(InvalidTenantSchema::class);

        app(ProposeTenantSchema::class)->propose(new ProposeTenantSchemaCommand(
            projectId: self::PROJECT,
            uniqueKey: 'code_etablissement',
            uniqueKeyPattern: 'AE-[0-9]{4}',
            fields: [[
                'key' => 'courriel',
                'label' => 'Courriel',
                'type' => 'email',
                'required' => false,
                'max_length' => 254,
                'source' => 'core_identity.email',
            ]],
            actor: 'project:'.self::PROJECT,
            correlationId: null,
        ));
    }

    #[Test]
    public function a_declaration_may_not_carry_more_than_thirty_two_fields(): void
    {
        $fields = [];

        for ($index = 0; $index < 33; $index++) {
            $fields[] = [
                'key' => 'champ'.$index,
                'label' => 'Champ '.$index,
                'type' => 'string',
                'required' => false,
                'max_length' => null,
                'source' => 'operator',
            ];
        }

        $this->expectException(InvalidTenantSchema::class);

        app(ProposeTenantSchema::class)->propose(new ProposeTenantSchemaCommand(
            projectId: self::PROJECT,
            uniqueKey: 'code_etablissement',
            uniqueKeyPattern: 'AE-[0-9]{4}',
            fields: $fields,
            actor: 'project:'.self::PROJECT,
            correlationId: null,
        ));
    }

    #[Test]
    public function a_pattern_may_not_authorise_a_reference_outside_the_core_alphabet(): void
    {
        $this->expectException(InvalidTenantSchema::class);

        // The wildcard would match a character the Core refuses.
        app(ProposeTenantSchema::class)->propose(new ProposeTenantSchemaCommand(
            projectId: self::PROJECT,
            uniqueKey: 'code_etablissement',
            uniqueKeyPattern: 'AE-.*',
            fields: $this->fields(),
            actor: 'project:'.self::PROJECT,
            correlationId: null,
        ));
    }

    #[Test]
    public function the_pattern_is_checked_against_the_reference(): void
    {
        $proposed = $this->propose();
        app(AcceptTenantSchema::class)->accept(new AcceptTenantSchemaCommand(
            projectId: self::PROJECT,
            schemaId: $proposed->id,
            actor: 'operator:test',
            correlationId: null,
        ));

        $schema = app(GetTenantSchema::class);
        $scope = AccessScope::forProject(self::PROJECT);

        self::assertTrue($schema->acceptsReference(self::PROJECT, 'AE-0042', $scope));
        self::assertFalse($schema->acceptsReference(self::PROJECT, 'AE-42', $scope));
        self::assertFalse($schema->acceptsReference(self::PROJECT, 'AE 0042', $scope));
    }

    #[Test]
    public function the_declared_values_follow_the_form_in_force(): void
    {
        $proposed = $this->propose();
        app(AcceptTenantSchema::class)->accept(new AcceptTenantSchemaCommand(
            projectId: self::PROJECT,
            schemaId: $proposed->id,
            actor: 'operator:test',
            correlationId: null,
        ));

        $customer = $this->customer();

        $values = app(SetCustomerDeclaredAttributes::class)->set(
            new SetCustomerDeclaredAttributesCommand(
                projectId: self::PROJECT,
                customerId: $customer->id,
                values: ['localisation' => 'Douala'],
                actor: 'operator:test',
                correlationId: null,
            ),
            AccessScope::forProject(self::PROJECT),
        );

        self::assertSame(['localisation' => 'Douala'], $values);

        $read = app(GetCustomerDeclaredAttributes::class)->forCustomer(
            self::PROJECT,
            $customer->id,
            AccessScope::forProject(self::PROJECT),
        );

        self::assertSame(['localisation' => 'Douala'], $read);

        // The value is stored in clear, like `display_name` (ADR-0122 §5).
        $row = DB::table('customer_declared_attributes')->first();
        self::assertNotNull($row);
        self::assertSame('localisation', $row->key);
        self::assertSame('Douala', $row->value);
    }

    #[Test]
    public function a_value_outside_the_form_in_force_is_refused(): void
    {
        $proposed = $this->propose();
        app(AcceptTenantSchema::class)->accept(new AcceptTenantSchemaCommand(
            projectId: self::PROJECT,
            schemaId: $proposed->id,
            actor: 'operator:test',
            correlationId: null,
        ));

        $customer = $this->customer();

        $this->expectException(CustomerDeclaredAttributeRefused::class);

        app(SetCustomerDeclaredAttributes::class)->set(
            new SetCustomerDeclaredAttributesCommand(
                projectId: self::PROJECT,
                customerId: $customer->id,
                values: ['cle_inconnue' => 'valeur'],
                actor: 'operator:test',
                correlationId: null,
            ),
            AccessScope::forProject(self::PROJECT),
        );
    }

    #[Test]
    public function a_missing_mandatory_value_is_refused(): void
    {
        $proposed = $this->propose();
        app(AcceptTenantSchema::class)->accept(new AcceptTenantSchemaCommand(
            projectId: self::PROJECT,
            schemaId: $proposed->id,
            actor: 'operator:test',
            correlationId: null,
        ));

        $customer = $this->customer();

        $this->expectException(CustomerDeclaredAttributeRefused::class);

        app(SetCustomerDeclaredAttributes::class)->set(
            new SetCustomerDeclaredAttributesCommand(
                projectId: self::PROJECT,
                customerId: $customer->id,
                values: [],
                actor: 'operator:test',
                correlationId: null,
            ),
            AccessScope::forProject(self::PROJECT),
        );
    }

    #[Test]
    public function nothing_can_be_declared_without_a_form_in_force(): void
    {
        $customer = $this->customer();

        $this->expectException(CustomerDeclaredAttributeRefused::class);

        app(SetCustomerDeclaredAttributes::class)->set(
            new SetCustomerDeclaredAttributesCommand(
                projectId: self::PROJECT,
                customerId: $customer->id,
                values: ['localisation' => 'Douala'],
                actor: 'operator:test',
                correlationId: null,
            ),
            AccessScope::forProject(self::PROJECT),
        );
    }

    /**
     * A proposal, with the shape a driving school would declare.
     */
    private function propose(): TenantSchemaView
    {
        return app(ProposeTenantSchema::class)->propose(new ProposeTenantSchemaCommand(
            projectId: self::PROJECT,
            uniqueKey: 'code_etablissement',
            uniqueKeyPattern: 'AE-[0-9]{4}',
            fields: $this->fields(),
            actor: 'project:'.self::PROJECT,
            correlationId: null,
        ));
    }

    /**
     * @return list<array{key: string, label: string, type: string, required: bool, max_length: int|null, source: string}>
     */
    private function fields(): array
    {
        return [[
            'key' => 'localisation',
            'label' => 'Localisation',
            'type' => 'string',
            'required' => true,
            'max_length' => 120,
            'source' => 'operator',
        ]];
    }

    /**
     * The declaration in force, read through the port the console calls.
     */
    private function accepted(): ?TenantSchemaView
    {
        return app(GetTenantSchema::class)->accepted(
            self::PROJECT,
            AccessScope::forProject(self::PROJECT),
        );
    }

    private function customer(): CustomerView
    {
        return app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'AE-0042',
            displayName: 'Auto-école de Douala',
            email: null,
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
        ));
    }
}
