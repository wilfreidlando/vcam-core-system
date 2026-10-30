<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * Audit de sécurité 2026-10-29, écart #4 (ADR-0081 S-07) : un secret n'est utilisable qu'UNE
 * fois, à sa création — et ne doit jamais être écrit dans le store de session, où un flash
 * survit à la réponse qui le porte (Redis/Valkey en production, sauvegardes, dumps).
 *
 * Chaque test poste la création, lit le secret dans la réponse du POST lui-même, puis fouille
 * le store de session (ce que le gestionnaire a réellement écrit) : le secret n'y est pas, et
 * la page rechargée ne le montre plus.
 */
final class ConsoleSecretsAreNotSessionedTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function a_new_api_key_is_rendered_in_the_post_response_and_never_stored_in_the_session(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->signInToConsole();
        $project = $this->project();

        $response = $this->post("/admin/projects/{$project}/api-keys", ['name' => 'Versements', 'template' => 'payouts'])
            ->assertOk()
            ->assertSessionMissing('admin.api-keys.created');

        $secret = $this->secretIn($response, '/Affichée uniquement maintenant.*?<code[^>]*>([^<]+)<\/code>/s');
        $this->assertNotInSessionStore($secret);

        // Reload: the key is gone from the page, for good.
        $this->get("/admin/projects/{$project}/api-keys/create")->assertOk()->assertDontSee($secret);
    }

    #[Test]
    public function a_rotated_api_key_is_rendered_in_the_post_response_and_never_stored_in_the_session(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->signInToConsole();
        $project = $this->project();

        $this->post("/admin/projects/{$project}/api-keys", ['name' => 'Serveur', 'template' => 'server'])->assertOk();
        $keyId = \Illuminate\Support\Facades\DB::table('api_keys')->where('project_id', $project)->where('name', 'Serveur')->value('id');
        self::assertIsString($keyId);

        $response = $this->post("/admin/projects/{$project}/api-keys/{$keyId}/rotate", ['grace_days' => 3])
            ->assertOk()
            ->assertSee('Clé remplacée');

        $secret = $this->secretIn($response, '/Affichée uniquement maintenant.*?<code[^>]*>([^<]+)<\/code>/s');
        $this->assertNotInSessionStore($secret);
    }

    #[Test]
    public function a_new_webhook_signing_secret_is_rendered_in_the_post_response_and_never_stored_in_the_session(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->signInToConsole();
        $project = $this->project();

        $response = $this->post("/admin/projects/{$project}/webhook-endpoints", [
            'url' => 'https://example.com/webhooks/core',
            'categories' => ['payments'],
        ])->assertOk()->assertSessionMissing('admin.webhook-endpoints.created');

        $secret = $this->secretIn($response, '/Secret de signature.*?<code[^>]*>([^<]+)<\/code>/s');
        $this->assertNotInSessionStore($secret);

        $this->get("/admin/projects/{$project}/webhook-endpoints/create")->assertOk()->assertDontSee($secret);
    }

    #[Test]
    public function a_rotated_webhook_secret_is_rendered_in_the_post_response_and_never_stored_in_the_session(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->signInToConsole();
        $project = $this->project();
        $endpoint = app(RegisterWebhookEndpoint::class)->register(new RegisterWebhookEndpointCommand($project, 'https://example.com/hooks/secret', ['*']));

        $response = $this->post("/admin/projects/{$project}/webhook-endpoints/{$endpoint->id}/rotate-secret", ['overlap_hours' => 12])
            ->assertOk()
            ->assertSessionMissing('admin.webhook-endpoints.rotated');

        $secret = $this->secretIn($response, '/Nouveau secret de signature.*?<code[^>]*>([^<]+)<\/code>/s');
        $this->assertNotInSessionStore($secret);
    }

    #[Test]
    public function an_operator_invitation_token_is_rendered_in_the_post_response_and_never_stored_in_the_session(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->signInToConsole();

        $email = uniqid('invited-', true).'@example.com';
        $response = $this->post('/admin/operators', ['email' => $email, 'role' => 'support_operator'])
            ->assertOk()
            ->assertSee($email)
            ->assertSessionMissing('admin.operators.created');

        $token = $this->secretIn($response, "/Jeton d'activation.*?<code[^>]*>([^<]+)<\\/code>/s");
        $this->assertNotInSessionStore($token);

        $this->get('/admin/operators')->assertOk()->assertDontSee($token);
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Secrets Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Secrets Project'), actor: 'service:test', correlationId: null)->project->id;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function secretIn(TestResponse $response, string $pattern): string
    {
        $content = $response->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        $html = is_string($content) ? html_entity_decode($content) : '';

        self::assertSame(1, preg_match($pattern, $html, $found), 'the secret must be on the page that answers the POST');
        $secret = trim($found[1]);
        self::assertGreaterThanOrEqual(20, strlen($secret), 'what was captured is not a secret');

        return $secret;
    }

    /**
     * What the session handler actually holds for this browser session, and what the live
     * store would write: neither may contain the secret.
     */
    private function assertNotInSessionStore(string $secret): void
    {
        /** @var \Illuminate\Session\Store $store */
        $store = $this->app->make('session')->driver();
        $handler = $store->getHandler();
        $written = $handler->read($store->getId());
        self::assertIsString($written);

        self::assertStringNotContainsString($secret, $written, 'the secret was written to the session store');
        self::assertStringNotContainsString($secret, (string) json_encode($store->all()), 'the secret is in the live session');
    }
}
