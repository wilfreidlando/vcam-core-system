<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** ADR-0082 §5 — rotating a webhook secret never breaks the receiver. */
final class WebhookSecretRotationHttpTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function during_the_overlap_both_signatures_verify_then_only_the_new_one_remains(): void
    {
        Http::preventStrayRequests();
        Http::fake(['example.com/hooks/rotating' => Http::response('ok', 200)]);

        $auth = ['Authorization' => 'Bearer '.$this->projectToken()];
        $created = $this->postJson('/v1/webhook-endpoints', ['url' => 'https://example.com/hooks/rotating', 'events' => ['*']], $auth)->assertStatus(201);
        $endpointId = $created->json('id');
        $oldSecret = $created->json('secret');
        self::assertIsString($endpointId);
        self::assertIsString($oldSecret);

        $rotated = $this->postJson("/v1/webhook-endpoints/{$endpointId}/rotate-secret", ['overlap_hours' => 2], $auth)
            ->assertOk()
            ->assertJsonPath('id', $endpointId);
        $newSecret = $rotated->json('secret');
        self::assertIsString($newSecret);
        self::assertNotSame($oldSecret, $newSecret);
        self::assertNotNull($rotated->json('previous_secret_expires_at'));

        // The listing says the overlap is running, and never shows a secret.
        $listed = $this->getJson('/v1/webhook-endpoints', $auth)->assertOk();
        self::assertSame($rotated->json('previous_secret_expires_at'), $listed->json('data.0.previous_secret_expires_at'));
        $listing = $listed->json();
        self::assertIsArray($listing);
        $listingJson = json_encode($listing, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($newSecret, $listingJson);
        self::assertStringNotContainsString($oldSecret, $listingJson);

        $this->postJson("/v1/webhook-endpoints/{$endpointId}/test", [], $auth)->assertOk()->assertJsonPath('delivered', true);
        $request = $this->lastRequest();
        $signed = $this->timestampOf($request).'.'.$request->body();
        self::assertSame(hash_hmac('sha256', $signed, $newSecret), $request->header('X-Signature')[0] ?? null);
        self::assertSame(hash_hmac('sha256', $signed, $oldSecret), $request->header('X-Signature-Previous')[0] ?? null);

        // The overlap ends; the purge erases the old secret at rest.
        DB::table('webhook_endpoints')->where('id', $endpointId)->update(['previous_secret_expires_at' => now()->subMinute()]);
        self::assertSame(0, Artisan::call('webhooks:purge-expired-secrets'));
        self::assertNull(DB::table('webhook_endpoints')->where('id', $endpointId)->value('previous_secret_encrypted'));

        $this->postJson("/v1/webhook-endpoints/{$endpointId}/test", [], $auth)->assertOk();
        $request = $this->lastRequest();
        self::assertSame([], $request->header('X-Signature-Previous'));
        self::assertSame(hash_hmac('sha256', $this->timestampOf($request).'.'.$request->body(), $newSecret), $request->header('X-Signature')[0] ?? null);
        $this->getJson('/v1/webhook-endpoints', $auth)->assertJsonPath('data.0.previous_secret_expires_at', null);
    }

    #[Test]
    public function another_project_cannot_rotate_the_secret_and_the_overlap_is_bounded(): void
    {
        $owner = ['Authorization' => 'Bearer '.$this->projectToken('Owner')];
        $intruder = ['Authorization' => 'Bearer '.$this->projectToken('Intruder')];
        $endpointId = $this->postJson('/v1/webhook-endpoints', ['url' => 'https://example.com/hooks/owned', 'events' => ['*']], $owner)->json('id');
        self::assertIsString($endpointId);

        $this->postJson("/v1/webhook-endpoints/{$endpointId}/rotate-secret", [], $intruder)->assertNotFound();
        $this->postJson("/v1/webhook-endpoints/{$endpointId}/rotate-secret", ['overlap_hours' => 73], $owner)->assertUnprocessable();
        $this->postJson("/v1/webhook-endpoints/{$endpointId}/rotate-secret", [], $owner)->assertOk();
    }

    private function timestampOf(Request $request): string
    {
        $timestamp = $request->header('X-Timestamp')[0] ?? null;
        self::assertIsString($timestamp);

        return $timestamp;
    }

    private function lastRequest(): Request
    {
        $recorded = Http::recorded();
        $last = $recorded->last();
        self::assertIsArray($last);
        self::assertInstanceOf(Request::class, $last[0]);

        return $last[0];
    }

    private function projectToken(string $name = 'Rotation'): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand($name.' Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, $name.' Project'), actor: 'service:test', correlationId: null)->apiKey->plaintextToken;
    }
}
