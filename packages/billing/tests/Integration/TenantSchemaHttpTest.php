<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

use Core\Billing\Contracts\AcceptTenantSchema;
use Core\Billing\Contracts\AcceptTenantSchemaCommand;
use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Billing\Contracts\SetCustomerDeclaredAttributes;
use Core\Billing\Contracts\SetCustomerDeclaredAttributesCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Support\Access\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0122 §2, §4 over HTTP: the wire contract of the declared form of a
 * tenant — status codes, the scope that gates it, and the declared values
 * the customer renders.
 */
final class TenantSchemaHttpTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_project_proposes_the_form_of_its_tenant(): void
    {
        [$token, $projectId] = $this->project(['tenant_schema:write']);

        $response = $this->putJson('/v1/projects/'.$projectId.'/tenant-schema', $this->declaration(), $this->auth($token));

        $response->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('version', 1)
            ->assertJsonPath('project_id', $projectId)
            ->assertJsonPath('unique_key', 'code_etablissement')
            ->assertJsonPath('unique_key_pattern', 'AE-[0-9]{4}')
            ->assertJsonPath('fields.0.key', 'localisation')
            ->assertJsonPath('fields.0.type', 'string')
            ->assertJsonPath('fields.0.required', true)
            ->assertJsonPath('fields.0.max_length', 120)
            ->assertJsonPath('fields.0.source', 'operator');
    }

    #[Test]
    public function the_scope_gates_the_route(): void
    {
        [$token, $projectId] = $this->project(['customers:read']);

        $this->putJson('/v1/projects/'.$projectId.'/tenant-schema', $this->declaration(), $this->auth($token))
            ->assertForbidden();
    }

    #[Test]
    public function a_declaration_that_redeclares_a_source_of_the_core_is_refused(): void
    {
        [$token, $projectId] = $this->project(['tenant_schema:write']);

        $declaration = $this->declaration();
        $declaration['fields'] = [[
            'key' => 'courriel',
            'label' => 'Courriel',
            'type' => 'email',
            'required' => false,
            'max_length' => 254,
            'source' => 'core_identity.email',
        ]];

        $this->putJson('/v1/projects/'.$projectId.'/tenant-schema', $declaration, $this->auth($token))
            ->assertStatus(422)
            // The reserved source is refused at the edge: the request
            // never reaches the domain (the domain refuses it too — see
            // `TenantSchemaFlowTest`).
            ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'validation-failed'));
    }

    #[Test]
    public function a_project_cannot_declare_the_form_of_another_project(): void
    {
        [$token] = $this->project(['tenant_schema:write']);
        [, $otherProjectId] = $this->project(['tenant_schema:write']);

        $this->putJson('/v1/projects/'.$otherProjectId.'/tenant-schema', $this->declaration(), $this->auth($token))
            ->assertStatus(404);
    }

    #[Test]
    public function the_customer_renders_its_declared_values(): void
    {
        [$token, $projectId] = $this->project(['customers:write', 'customers:read', 'tenant_schema:write']);

        $this->putJson('/v1/customers/AE-0042', ['display_name' => 'Auto-école de Douala'], $this->auth($token))
            ->assertCreated();

        $this->acceptTheDeclaration($projectId, $token);

        $customer = $this->customerOf($projectId, 'AE-0042');

        app(SetCustomerDeclaredAttributes::class)->set(
            new SetCustomerDeclaredAttributesCommand(
                projectId: $projectId,
                customerId: $customer,
                values: ['localisation' => 'Douala'],
                actor: 'operator:test',
                correlationId: null,
            ),
            AccessScope::forProject($projectId),
        );

        $this->getJson('/v1/customers/AE-0042', $this->auth($token))
            ->assertOk()
            ->assertJsonPath('external_ref', 'AE-0042')
            ->assertJsonPath('declared_attributes.localisation', 'Douala');
    }

    #[Test]
    public function a_customer_without_declared_values_renders_an_empty_object(): void
    {
        [$token, $projectId] = $this->project(['customers:write', 'customers:read']);

        $this->putJson('/v1/customers/AE-0042', ['display_name' => 'Auto-école de Douala'], $this->auth($token))
            ->assertCreated();

        $this->getJson('/v1/customers/AE-0042', $this->auth($token))
            ->assertOk()
            ->assertJsonPath('declared_attributes', []);
    }

    /**
     * The operator accepts the declaration the application proposed.
     */
    private function acceptTheDeclaration(string $projectId, string $token): void
    {
        $proposed = $this->putJson(
            '/v1/projects/'.$projectId.'/tenant-schema',
            $this->declaration(),
            $this->auth($token),
        )->assertCreated()->json();

        self::assertIsArray($proposed);
        self::assertIsString($proposed['id']);

        app(AcceptTenantSchema::class)->accept(new AcceptTenantSchemaCommand(
            projectId: $projectId,
            schemaId: $proposed['id'],
            actor: 'operator:test',
            correlationId: null,
        ));
    }

    private function customerOf(string $projectId, string $externalRef): string
    {
        $customer = app(GetCustomer::class)->get(
            new GetCustomerCommand($projectId, $externalRef),
            AccessScope::forProject($projectId),
        );

        self::assertNotNull($customer);

        return $customer->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function declaration(): array
    {
        return [
            'unique_key' => 'code_etablissement',
            'unique_key_pattern' => 'AE-[0-9]{4}',
            'fields' => [[
                'key' => 'localisation',
                'label' => 'Localisation',
                'type' => 'string',
                'required' => true,
                'max_length' => 120,
                'source' => 'operator',
            ]],
        ];
    }

    /**
     * @param list<string> $scopes
     * @return array{0: string, 1: string}
     */
    private function project(array $scopes): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project;
        $issued = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project->id, $scopes), actor: 'service:test', correlationId: null);

        return [$issued->plaintextToken, $project->id];
    }

    /**
     * @return array<string, string>
     */
    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }
}
