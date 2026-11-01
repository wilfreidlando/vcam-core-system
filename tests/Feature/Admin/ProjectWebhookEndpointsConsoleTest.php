<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Identity\OperatorRole;
use Core\Support\Access\AccessScope;
use Core\Webhook\Contracts\ListWebhookEndpoints;
use Core\Webhook\Contracts\ListWebhookEndpointsCommand;
use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Core\Webhook\Contracts\WebhookEndpointView;
use Core\Webhook\Contracts\WebhookEventTypes;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * ADR-0110 §5 / ADR-0107 §6 — the console page where an operator
 * registers the webhook endpoint of a **client application**.
 *
 * The application never registers it itself, and never holds a webhook
 * secret of its own: the operator registers the URL, chooses the events,
 * and hands the secret over out of band. What the console owes the
 * operator, and what this file proves:
 *
 *  - the secret is shown **once**, and never again;
 *  - the rotation shows the new secret once and keeps the previous one
 *    signing for the overlap;
 *  - a URL already registered, or a URL that is not safely reachable, is
 *    refused with a reason;
 *  - an operator of another organization gets "not found" — never
 *    "forbidden", which would confirm the resource exists (ADR-0081 S-04);
 *  - an account without the permission is refused.
 */
final class ProjectWebhookEndpointsConsoleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_operator_registers_the_endpoint_of_a_client_application_and_the_secret_is_shown_once(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->signIn();
        $projectId = $this->project('Console Org', 'Console Project');

        $page = $this->get("/admin/projects/{$projectId}/webhook-endpoints");
        $page->assertOk();
        // The heading is static text: it is not escaped by Blade.
        $page->assertSee('Enregistrer le point d\'entrée d\'une application cliente', false);

        $response = $this->post("/admin/projects/{$projectId}/webhook-endpoints", [
            'url' => 'https://example.com/webhooks/core',
            'events' => ['customer.created', 'subscription.renewed'],
        ]);
        $response->assertOk();
        $response->assertSessionMissing('admin.webhook-endpoints.created');

        // The operator is told, in plain words, that the secret is shown
        // once and only once.
        $response->assertSee('copiez-le maintenant, il ne sera plus jamais affiché', false);

        $secret = $this->secretIn($response);
        self::assertStringStartsWith('whsec_', $secret);

        // The endpoint exists, with the events the operator chose.
        $endpoints = $this->endpoints($projectId);
        self::assertCount(1, $endpoints);
        self::assertSame('https://example.com/webhooks/core', $endpoints[0]->url);
        self::assertSame(['customer.created', 'subscription.renewed'], $endpoints[0]->events);

        // The gesture is audited.
        self::assertSame(1, DB::table('audit_log')
            ->where('resource', 'webhook_endpoint:'.$endpoints[0]->id)
            ->where('action', 'webhook_endpoint.registered')
            ->count());

        // The secret is shown ONCE: the next page does not carry it.
        $this->get("/admin/projects/{$projectId}/webhook-endpoints")
            ->assertOk()
            ->assertDontSee($secret);
    }

    #[Test]
    public function the_page_offers_the_whole_event_catalogue_and_pre_selects_the_commerce_events(): void
    {
        $this->signIn();
        $projectId = $this->project('Catalogue Org', 'Catalogue Project');

        $html = $this->html($this->get("/admin/projects/{$projectId}/webhook-endpoints")->assertOk());

        // Every event type the Core emits is offered — the catalogue is
        // `WebhookEventTypes::ALL`, the same closed list the JSON API
        // validates against.
        foreach (WebhookEventTypes::ALL as $type) {
            self::assertStringContainsString('value="'.$type.'"', $html, $type.' is not offered by the form');
        }

        // The commerce of the project — what a client application needs
        // (ADR-0107 §1 and §3) — is ticked by default.
        foreach (['customer.created', 'subscription.renewed', 'invoice.paid', 'usage.limit_reached'] as $type) {
            self::assertMatchesRegularExpression(
                '/value="'.preg_quote($type, '/').'"[^>]*\schecked[\s>]/',
                $html,
                $type.' should be pre-selected',
            );
        }

        // The others are not.
        foreach (['payment.status_changed', 'wallet.credited', 'notification.delivered', 'plan.archived'] as $type) {
            self::assertDoesNotMatchRegularExpression(
                '/value="'.preg_quote($type, '/').'"[^>]*\schecked[\s>]/',
                $html,
                $type.' should not be pre-selected',
            );
        }
    }

    #[Test]
    public function registering_the_same_url_twice_is_refused_with_a_readable_reason(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->signIn();
        $projectId = $this->project('Duplicate Org', 'Duplicate Project');

        $this->post("/admin/projects/{$projectId}/webhook-endpoints", [
            'url' => 'https://example.com/webhooks/core',
            'events' => ['customer.created'],
        ])->assertOk();

        $second = $this->post("/admin/projects/{$projectId}/webhook-endpoints", [
            'url' => 'https://example.com/webhooks/core',
            'events' => ['invoice.paid'],
        ]);
        $second->assertRedirect();
        $second->assertSessionHasErrors('url');
        self::assertStringContainsString('livré deux fois', $this->flashedErrors());

        // The refused registration created nothing.
        self::assertCount(1, $this->endpoints($projectId));
    }

    #[Test]
    public function an_unsafe_url_is_refused(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->signIn();
        $projectId = $this->project('Unsafe Org', 'Unsafe Project');

        // The same rule the JSON API applies (`SafeOutboundUrlRule`): a
        // loopback address is never a receiver.
        $refused = $this->post("/admin/projects/{$projectId}/webhook-endpoints", [
            'url' => 'http://127.0.0.1/hook',
            'events' => ['customer.created'],
        ]);
        $refused->assertRedirect();
        $refused->assertSessionHasErrors('url');

        self::assertCount(0, $this->endpoints($projectId));
    }

    #[Test]
    public function deactivating_and_rotating_work_and_the_new_secret_is_shown_once(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->signIn();
        $projectId = $this->project('Rotation Org', 'Rotation Project');

        $endpoint = app(RegisterWebhookEndpoint::class)->register(
            new RegisterWebhookEndpointCommand($projectId, 'https://example.com/hooks/original', ['*']),
        );

        $deactivate = $this->post("/admin/projects/{$projectId}/webhook-endpoints/{$endpoint->id}/deactivate");
        $deactivate->assertRedirect(route('admin.projects.webhook-endpoints.index', ['projectId' => $projectId]));
        self::assertFalse($this->endpoints($projectId)[0]->active);

        $rotate = $this->post("/admin/projects/{$projectId}/webhook-endpoints/{$endpoint->id}/rotate-secret", ['overlap_hours' => 12]);
        $rotate->assertOk();

        $newSecret = $this->secretIn($rotate, '/Nouveau secret de signature.*?<code[^>]*>([^<]+)<\/code>/s');

        // The overlap is announced: the previous secret keeps signing.
        $rotate->assertSee('X-Signature-Previous', false);
        self::assertNotNull($this->endpoints($projectId)[0]->previous_secret_expires_at);

        // The new secret is shown once, like the first one — and the
        // rotation window is on the page: the previous secret still signs.
        $page = $this->get("/admin/projects/{$projectId}/webhook-endpoints");
        $page->assertOk();
        $page->assertDontSee($newSecret);
        $page->assertSee('Double signature active', false);
        $page->assertSee('X-Signature-Previous', false);

        // And the rotation is audited, without the secret in the trail.
        $entry = DB::table('audit_log')
            ->where('resource', 'webhook_endpoint:'.$endpoint->id)
            ->where('action', 'webhook_endpoint.secret_rotated')
            ->first();
        self::assertNotNull($entry);
        self::assertIsString($entry->after);
        self::assertStringNotContainsString($newSecret, $entry->after);
    }

    #[Test]
    public function the_endpoint_can_be_tested_and_the_gesture_is_audited(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        Http::preventStrayRequests();
        Http::fake(['example.com/*' => Http::response('ok', 200)]);

        $this->signIn();
        $projectId = $this->project('Test Org', 'Test Project');

        $endpoint = app(RegisterWebhookEndpoint::class)->register(
            new RegisterWebhookEndpointCommand($projectId, 'https://example.com/hooks/receiver', ['*']),
        );

        $response = $this->post("/admin/projects/{$projectId}/webhook-endpoints/{$endpoint->id}/test");
        $response->assertOk();
        $response->assertSee('Le destinataire a bien reçu');

        self::assertSame(1, DB::table('audit_log')
            ->where('resource', 'webhook_endpoint:'.$endpoint->id)
            ->where('action', 'webhook_endpoint.tested')
            ->count());
    }

    #[Test]
    public function the_listing_says_when_each_endpoint_was_last_called(): void
    {
        $this->signIn();
        $projectId = $this->project('Delivery Org', 'Delivery Project');

        $endpoint = app(RegisterWebhookEndpoint::class)->register(
            new RegisterWebhookEndpointCommand($projectId, 'https://example.com/hooks/receiver', ['customer.created']),
        );

        // Nothing was ever delivered: the column says so.
        $this->get("/admin/projects/{$projectId}/webhook-endpoints")
            ->assertOk()
            ->assertSee('jamais');

        $deliveredAt = new DateTimeImmutable('2026-10-30 08:15:00');
        app(WebhookDeliveryRepository::class)->create(new WebhookDelivery(
            id: (string) Str::uuid(),
            endpointId: $endpoint->id,
            projectId: $projectId,
            eventId: (string) Str::uuid(),
            eventType: 'customer.created',
            payload: [],
            status: WebhookDeliveryStatus::Delivered,
            attempts: 1,
            nextRetryAt: null,
            lastAttemptAt: $deliveredAt,
            lastError: null,
            deliveredAt: $deliveredAt,
        ));

        $page = $this->get("/admin/projects/{$projectId}/webhook-endpoints")->assertOk();
        $page->assertSee('delivered');
        $page->assertSee('30/10/2026 08:15');
    }

    #[Test]
    public function an_operator_of_another_organization_gets_not_found_never_forbidden(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $organizationA = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Org A'),
            actor: 'service:test',
            correlationId: null,
        );
        $projectA = app(CreateProject::class)->create(
            new CreateProjectCommand($organizationA->id, 'Project A'),
            actor: 'service:test',
            correlationId: null,
        )->project;

        $organizationB = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Org B'),
            actor: 'service:test',
            correlationId: null,
        );
        $projectB = app(CreateProject::class)->create(
            new CreateProjectCommand($organizationB->id, 'Project B'),
            actor: 'service:test',
            correlationId: null,
        )->project;

        $endpointB = app(RegisterWebhookEndpoint::class)->register(
            new RegisterWebhookEndpointCommand($projectB->id, 'https://example.com/hooks/b', ['*']),
        );

        $this->signIn($this->organizationOperator($organizationA->id, OrganizationRole::Owner));

        // B's project page: "not found", never "forbidden".
        $this->get("/admin/projects/{$projectB->id}/webhook-endpoints")->assertNotFound();

        // B's endpoint, addressed under B's project.
        $this->post("/admin/projects/{$projectB->id}/webhook-endpoints/{$endpointB->id}/deactivate")->assertNotFound();

        // B's endpoint, addressed under A's project: the console's
        // "not found" answer — and certainly not a 403.
        $underOwnParent = $this->post("/admin/projects/{$projectA->id}/webhook-endpoints/{$endpointB->id}/deactivate");
        self::assertNotSame(403, $underOwnParent->baseResponse->getStatusCode());
        $underOwnParent->assertRedirect();
        $underOwnParent->assertSessionHasErrors('endpoint');

        // And B's endpoint is untouched.
        self::assertTrue($this->endpoints($projectB->id)[0]->active);
    }

    #[Test]
    public function an_operator_without_the_permission_is_forbidden(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Viewer Org'),
            actor: 'service:test',
            correlationId: null,
        );
        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Viewer Project'),
            actor: 'service:test',
            correlationId: null,
        )->project;

        $endpoint = app(RegisterWebhookEndpoint::class)->register(
            new RegisterWebhookEndpointCommand($project->id, 'https://example.com/hooks/viewer', ['*']),
        );

        // `org_viewer` holds no `webhook-endpoints:write` — the writes are
        // refused before any lookup (403, never a hint about the resource).
        $this->signIn($this->organizationOperator($organization->id, OrganizationRole::Viewer));

        $this->get("/admin/projects/{$project->id}/webhook-endpoints")->assertForbidden();

        $this->post("/admin/projects/{$project->id}/webhook-endpoints", [
            'url' => 'https://example.com/hooks/other',
            'events' => ['customer.created'],
        ])->assertForbidden();

        $this->post("/admin/projects/{$project->id}/webhook-endpoints/{$endpoint->id}/deactivate")->assertForbidden();
        $this->post("/admin/projects/{$project->id}/webhook-endpoints/{$endpoint->id}/rotate-secret", ['overlap_hours' => 12])->assertForbidden();
        $this->post("/admin/projects/{$project->id}/webhook-endpoints/{$endpoint->id}/test")->assertForbidden();

        // Nothing was changed on the way out.
        self::assertTrue($this->endpoints($project->id)[0]->active);
    }

    /**
     * A console account of an organization, with no platform role: it sees
     * what its own organization gives it, and nothing else.
     */
    private function organizationOperator(string $organizationId, OrganizationRole $role): string
    {
        $email = uniqid('op-', true).'@example.com';
        $operatorId = OperatorFactory::create($email, null);

        app(OrganizationMembershipRepository::class)->save(new OrganizationMembership(
            id: (string) Str::uuid(),
            organizationId: $organizationId,
            operatorId: $operatorId,
            role: $role,
            createdAt: new DateTimeImmutable,
        ));

        return $email;
    }

    /**
     * Signs in the way a person does: email + password, then the
     * enrolment of the authenticator and its first code.
     */
    private function signIn(?string $email = null): void
    {
        if ($email === null) {
            $email = uniqid('op-', true).'@example.com';
            OperatorFactory::create($email, OperatorRole::PlatformAdmin);
        }

        $operator = app(OperatorRepository::class)->findByEmail($email);
        self::assertInstanceOf(Operator::class, $operator);

        $this->post('/admin/login', [
            'email' => $email,
            'password' => OperatorFactory::PASSWORD,
        ])->assertRedirect(route('admin.mfa.enroll.show'));

        $html = $this->html($this->get('/admin/mfa/enroll'));
        $totp = preg_match('/name="secret"\s+value="([A-Z2-7]+)"/', $html, $secret) === 1
            ? $secret[1]
            : (preg_match('/<code[^>]*>([A-Z2-7]{16,32})<\/code>/', $html, $code) === 1 ? $code[1] : '');
        self::assertNotSame('', $totp, 'the TOTP secret is missing');

        $challengeToken = preg_match('/name="challenge_token"\s+value="([^"]+)"/', $html, $challenge) === 1 ? $challenge[1] : '';
        self::assertNotSame('', $challengeToken, 'the challenge token is missing');

        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $challengeToken,
            'code' => (new Google2FA)->getCurrentOtp($totp),
        ])->assertRedirect(route('admin.dashboard'));
    }

    private function project(string $organizationName, string $projectName): string
    {
        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand($organizationName),
            actor: 'service:test',
            correlationId: null,
        );

        return app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, $projectName),
            actor: 'service:test',
            correlationId: null,
        )->project->id;
    }

    /**
     * @return list<WebhookEndpointView>
     */
    private function endpoints(string $projectId): array
    {
        return app(ListWebhookEndpoints::class)->list(
            new ListWebhookEndpointsCommand($projectId),
            AccessScope::platform(),
        );
    }

    /**
     * The secret carried by the response that created or rotated it.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     * @param string|null $pattern the shape to look for — the creation
     *                             and the rotation do not say the same
     *                             thing
     */
    private function secretIn(TestResponse $response, ?string $pattern = null): string
    {
        $html = $this->html($response);
        $pattern ??= '/Secret de signature.*?<code[^>]*>([^<]+)<\/code>/s';

        $captured = preg_match($pattern, $html, $found) === 1 ? $found[1] : '';
        self::assertNotSame('', $captured, 'the secret must be on the page that answers the POST');

        $secret = trim($captured);
        self::assertGreaterThanOrEqual(20, strlen($secret), 'what was captured is not a secret');

        return $secret;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function html(TestResponse $response): string
    {
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $response->getContent();

        return is_string($content) ? $content : '';
    }

    /**
     * The messages the last refused write left for the operator.
     */
    private function flashedErrors(): string
    {
        $errors = session('errors');

        if ($errors instanceof ViewErrorBag) {
            return implode(' ', array_filter($errors->getBag('default')->all(), is_string(...)));
        }

        // The session of this application is JSON-serialized: the error bag
        // comes back as its array form.
        $messages = data_get($errors, 'default.messages', []);

        if (! is_array($messages)) {
            return '';
        }

        return implode(' ', array_filter(Arr::flatten($messages), is_string(...)));
    }
}
