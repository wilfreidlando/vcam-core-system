<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Identity\Contracts\ApiKeyScopes;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Identity\Contracts\IssuedApiKeyView;
use Core\Identity\Contracts\RotateApiKey;
use Core\Identity\Contracts\RotateApiKeyCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** ADR-0082 — scoped, named, network-restricted, rotatable API keys, end to end. */
final class ScopedApiKeysHttpTest extends TestCase
{
    use RefreshDatabase;

    private string $projectId;

    #[Test]
    public function a_key_only_does_what_its_scopes_allow(): void
    {
        $portal = $this->issue(ApiKeyScopes::template(ApiKeyScopes::TEMPLATE_PORTAL), name: 'Portail');
        $readOnly = $this->issue(ApiKeyScopes::template(ApiKeyScopes::TEMPLATE_READ_ONLY), name: 'Lecture');

        $this->getJson('/v1/webhook-endpoints', $this->auth($portal))->assertForbidden();
        $this->getJson('/v1/webhook-endpoints', $this->auth($readOnly))->assertOk();
        $this->postJson('/v1/webhook-endpoints', ['url' => 'https://example.com/hook', 'events' => ['payment.status_changed']], $this->auth($readOnly))->assertForbidden();

        $this->getJson('/v1/whoami', $this->auth($portal))->assertOk();
    }

    #[Test]
    public function a_restricted_key_is_refused_outside_its_network_and_the_refusal_says_why(): void
    {
        $key = $this->issue(['payments:read'], cidrs: ['203.0.113.0/24']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->getJson('/v1/whoami', $this->auth($key))
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://errors.core-system.internal/key-origin-not-allowed');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
            ->getJson('/v1/whoami', $this->auth($key))
            ->assertOk();

        self::assertSame('203.0.113.40', DB::table('api_keys')->where('id', $key->apiKeyId)->value('last_used_ip'));
    }

    #[Test]
    public function an_expired_key_gets_the_same_401_as_an_unknown_one(): void
    {
        $key = $this->issue(['payments:read']);
        $this->getJson('/v1/whoami', $this->auth($key))->assertOk();

        DB::table('api_keys')->where('id', $key->apiKeyId)->update(['expires_at' => now()->subSecond()]);

        $this->getJson('/v1/whoami', $this->auth($key))->assertStatus(401);
    }

    #[Test]
    public function rotation_keeps_both_keys_working_until_the_old_one_retires(): void
    {
        $old = $this->issue(['payments:read', 'webhooks:read'], name: 'Serveur');

        $new = app(RotateApiKey::class)->rotate(new RotateApiKeyCommand($old->apiKeyId, projectId: $this->projectId, graceDays: 7), actor: 'service:test', correlationId: null);

        $this->getJson('/v1/whoami', $this->auth($old))->assertOk();
        $this->getJson('/v1/whoami', $this->auth($new))->assertOk()->assertJsonPath('scopes', ['payments:read', 'webhooks:read']);

        $retiresAt = DB::table('api_keys')->where('id', $old->apiKeyId)->value('expires_at');
        self::assertIsString($retiresAt);
        self::assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), strtotime($retiresAt), 5);

        // The grace period ends.
        DB::table('api_keys')->where('id', $old->apiKeyId)->update(['expires_at' => now()->subSecond()]);

        $this->getJson('/v1/whoami', $this->auth($old))->assertStatus(401);
        $this->getJson('/v1/whoami', $this->auth($new))->assertOk();
    }

    #[Test]
    public function a_project_holds_at_most_ten_active_keys(): void
    {
        // The auto-provisioned « Clé principale » is the first.
        for ($i = 2; $i <= 10; $i++) {
            $this->issue(['payments:read'], name: "Clé {$i}");
        }

        try {
            $this->issue(['payments:read'], name: 'Clé 11');
            self::fail('The eleventh key should have been refused.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('10 clés actives', $e->getMessage());
        }

        self::assertSame(10, DB::table('api_keys')->where('project_id', $this->projectId)->count());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Keys Org'), actor: 'service:test', correlationId: null);
        $this->projectId = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Keys Project'), actor: 'service:test', correlationId: null)->project->id;
    }

    /**
     * @param list<string> $scopes
     * @param list<string> $cidrs
     */
    private function issue(array $scopes, string $name = 'Test', array $cidrs = []): IssuedApiKeyView
    {
        return app(IssueApiKey::class)->issue(
            new IssueApiKeyCommand($this->projectId, $scopes, name: $name, allowedCidrs: $cidrs),
            actor: 'service:test',
            correlationId: null,
        );
    }

    /** @return array<string, string> */
    private function auth(IssuedApiKeyView $key): array
    {
        return ['Authorization' => 'Bearer '.$key->plaintextToken];
    }
}
