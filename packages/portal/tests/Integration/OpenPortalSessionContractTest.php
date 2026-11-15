<?php

declare(strict_types=1);

namespace Core\Portal\Tests\Integration;

use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Portal\Application\UseCase\OpenPortalSessionUseCase;
use Core\Portal\Contracts\OpenPortalSession;
use Core\Portal\Contracts\OpenPortalSessionCommand;
use Core\Portal\Domain\Model\PortalSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0125 — opening a customer's space is one gesture, two doors: the
 * API (`POST /v1/portal/sessions`) and the console (the customer's
 * sheet). Both go through the module's published contract.
 *
 * What the contract promises — and what the console relies on:
 *
 *  - it answers a **one-time link**, the portal's own door;
 *  - the link lives **ten minutes** (`token_expires_at`), and the row
 *    agrees with the answer;
 *  - the API's answer does not change: the same fields, the same codes.
 */
final class OpenPortalSessionContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_contract_is_the_use_case_the_console_calls(): void
    {
        self::assertInstanceOf(OpenPortalSessionUseCase::class, app(OpenPortalSession::class));
    }

    #[Test]
    public function the_contract_answers_a_one_time_link_that_expires_in_ten_minutes(): void
    {
        $projectId = $this->project();
        $this->customer($projectId, 'AE0001');

        $opened = app(OpenPortalSession::class)->open(new OpenPortalSessionCommand($projectId, 'AE0001'));

        // The link is the portal's own door.
        self::assertStringContainsString('/portal/enter/', $opened->url);

        // Nothing was declared, nothing was subscribed: the call only
        // opened a session.
        self::assertFalse($opened->customerCreated);
        self::assertFalse($opened->subscriptionCreated);

        $session = DB::table('portal_sessions')->first();
        self::assertNotNull($session);
        self::assertSame($projectId, $session->project_id);
        self::assertSame('AE0001', $session->customer_ref);
        self::assertSame('manage', $session->flow);
        self::assertNull($session->consumed_at);

        // The window is the module's own: ten minutes — and the row says
        // exactly what the answer said.
        self::assertSame(600, PortalSession::TOKEN_TTL_SECONDS);
        self::assertEqualsWithDelta(
            Carbon::now()->addSeconds(PortalSession::TOKEN_TTL_SECONDS)->timestamp,
            Carbon::parse($opened->expiresAt)->timestamp,
            5,
        );

        $tokenExpiresAt = $session->token_expires_at;
        self::assertIsString($tokenExpiresAt);
        self::assertEqualsWithDelta(
            Carbon::parse($opened->expiresAt)->timestamp,
            Carbon::parse($tokenExpiresAt)->timestamp,
            1,
        );
    }

    #[Test]
    public function the_api_answers_the_same_fields_as_before(): void
    {
        [$token, $projectId] = $this->projectWithKey(['portal:write']);
        $this->customer($projectId, 'AE0001');

        $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001'], $this->auth($token))
            ->assertStatus(201)
            ->assertJsonStructure(['url', 'expires_at', 'customer_created', 'subscription_created'])
            ->assertJsonPath('customer_created', false)
            ->assertJsonPath('subscription_created', false);
    }

    #[Test]
    public function a_customer_the_project_does_not_know_is_refused(): void
    {
        $projectId = $this->project();

        $this->expectException(\Core\Portal\Domain\Exception\PortalCustomerNotFound::class);

        app(OpenPortalSession::class)->open(new OpenPortalSessionCommand($projectId, 'AE0001'));
    }

    // ------------------------------------------------------------------ helpers

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project;

        return $project->id;
    }

    /**
     * @param list<string> $scopes
     * @return array{0: string, 1: string}
     */
    private function projectWithKey(array $scopes): array
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

    private function customer(string $projectId, string $ref): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: $ref, displayName: 'Client', email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
    }
}
