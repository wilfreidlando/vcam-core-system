<?php

declare(strict_types=1);

namespace Tests\Contract;

use Core\Identity\Contracts\ApiKeyScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsWithKeys;
use Tests\TestCase;

/**
 * ADR-0107 §5 — the « Application cliente » scope template must give a client
 * application exactly what it needs, and **mechanically** deny it the power
 * to do commerce.
 *
 * The guarantee is not a convention the code must respect: it is the set of
 * scopes the key carries. An application that holds this template cannot
 * create a customer, a subscription or an invoice — whatever its code does.
 * That is what makes the architecture a fact and not a promise.
 *
 * The scopes that made the old kit fail are exactly the ones under test:
 * `usage:read` (the rights of a tenant) and `usage:write` (its usage) were
 * missing from the « Portail uniquement » template, so the gate of every
 * client application answered `403` — G3.
 */
final class ClientApplicationScopeTest extends TestCase
{
    use ProjectsWithKeys;
    use RefreshDatabase;

    #[Test]
    public function the_template_carries_exactly_the_scopes_a_client_application_needs(): void
    {
        self::assertSame(
            [
                'customers:read',
                'usage:read',
                'usage:write',
                'portal:write',
                'subscriptions:read',
                'invoices:read',
                'notifications:write',
                'notifications:read',
            ],
            ApiKeyScopes::template(ApiKeyScopes::TEMPLATE_CLIENT_APP),
        );
    }

    #[Test]
    public function the_template_grants_no_write_scope_on_the_commerce(): void
    {
        $forbidden = ['customers:write', 'subscriptions:write', 'invoices:write', 'plans:write'];

        foreach (ApiKeyScopes::template(ApiKeyScopes::TEMPLATE_CLIENT_APP) as $scope) {
            self::assertNotContains(
                $scope,
                $forbidden,
                sprintf('`%s` would let a client application do the commerce itself — that is the portal\'s job (ADR-0107 §5).', $scope),
            );
        }
    }

    #[Test]
    public function the_client_application_cannot_do_commerce(): void
    {
        $project = $this->makeProject('Client App');
        $client = $this->keyFor($project['project_id'], ApiKeyScopes::template(ApiKeyScopes::TEMPLATE_CLIENT_APP), 'Client app');

        $this->putJson('/v1/customers/acme', ['display_name' => 'ACME'], $client)->assertForbidden();
        $this->postJson('/v1/plans', ['code' => 'pro', 'name' => 'Pro'], $client)->assertForbidden();
        $this->withHeaders(['Idempotency-Key' => 'sub-1'])
            ->postJson('/v1/subscriptions', ['customer_ref' => 'acme', 'plan_code' => 'pro'], $client)
            ->assertForbidden();
        $this->withHeaders(['Idempotency-Key' => 'inv-1'])->postJson('/v1/invoices', [
            'customer_ref' => 'acme',
            'currency' => 'XOF',
            'lines' => [['description' => 'Abonnement', 'quantity' => 1, 'unit_amount_minor' => 1000]],
        ], $client)->assertForbidden();
    }

    #[Test]
    public function the_client_application_reads_its_rights_and_its_commercial_state(): void
    {
        $project = $this->makeProject('Client App');
        $server = $this->keyFor($project['project_id'], ApiKeyScopes::template(ApiKeyScopes::TEMPLATE_SERVER), 'Server');
        $client = $this->keyFor($project['project_id'], ApiKeyScopes::template(ApiKeyScopes::TEMPLATE_CLIENT_APP), 'Client app');

        // The Core creates the customer — the client's job (ADR-0107 §1).
        $this->putJson('/v1/customers/acme', ['display_name' => 'ACME'], $server)->assertCreated();

        $this->getJson('/v1/customers/acme', $client)->assertOk();
        $this->getJson('/v1/customers/acme/entitlements', $client)->assertOk();
        $this->getJson('/v1/subscriptions', $client)->assertOk();
        $this->getJson('/v1/invoices', $client)->assertOk();
    }

    #[Test]
    public function the_client_application_opens_the_portal_of_its_tenant(): void
    {
        $project = $this->makeProject('Client App');
        $server = $this->keyFor($project['project_id'], ApiKeyScopes::template(ApiKeyScopes::TEMPLATE_SERVER), 'Server');
        $client = $this->keyFor($project['project_id'], ApiKeyScopes::template(ApiKeyScopes::TEMPLATE_CLIENT_APP), 'Client app');

        $this->putJson('/v1/customers/acme', ['display_name' => 'ACME'], $server)->assertCreated();

        // Only `customer_ref`: the client neither creates the customer nor the
        // subscription (that would need `customers:write` and
        // `subscriptions:write`). The commerce happens in the portal.
        $response = $this->postJson('/v1/portal/sessions', ['customer_ref' => 'acme'], $client);

        $response->assertCreated();
        self::assertStringContainsString('/portal/enter/', self::text($response->json('url')));
    }
}
