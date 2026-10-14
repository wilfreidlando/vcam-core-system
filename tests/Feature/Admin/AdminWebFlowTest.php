<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\CreateOperator;
use Core\Identity\Contracts\CreateOperatorCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use Core\Support\Money\Money;
use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletRepository;
use Core\Webhook\Contracts\ListWebhookEndpoints;
use Core\Webhook\Contracts\ListWebhookEndpointsCommand;
use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The admin web console (session/cookie auth, Blade views) — distinct
 * from tests/Integration/AdminHttpFlowTest.php's bearer-token API
 * coverage. Exercises real HTTP form submissions through routes/admin.php,
 * `AuthController`, `ResolveOperatorSession` and the Blade views.
 *
 * Review finding, Lot 6 follow-up: this whole plane shipped with every
 * authenticated page fatally erroring (a static call to a nonexistent
 * Sanctum method, unregistered Blade components, array-access on
 * Contracts View objects, a missing import) — none of it caught because
 * PHPStan excluded this code and `tests/Feature` was never registered as
 * a testsuite, so nothing here ever ran.
 */
final class AdminWebFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_fresh_operator_logs_in_via_the_web_console_and_reaches_every_authenticated_page(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $created = app(CreateOperator::class)->create(
            new CreateOperatorCommand('webflow@example.com', 'Web Flow', OperatorRole::PlatformAdmin->value),
            actor: 'service:test',
            correlationId: null,
        );

        $this->get('/admin/login')->assertOk();

        $login = $this->post('/admin/login', [
            'email' => 'webflow@example.com',
            'password' => $created->plaintextPassword,
        ]);
        $login->assertRedirect(route('admin.mfa.enroll.show'));

        $enroll = $this->get('/admin/mfa/enroll');
        $enroll->assertOk();
        $secret = $this->extractSecret($enroll);
        $challengeToken = $this->extractHiddenField($enroll, 'challenge_token');

        $code = (new Google2FA)->getCurrentOtp($secret);

        $confirm = $this->post('/admin/mfa/enroll', [
            'challenge_token' => $challengeToken,
            'code' => $code,
        ]);
        $confirm->assertRedirect(route('admin.dashboard'));

        // Every authenticated page renders 200 with real content — the
        // exact set that used to fatally error end to end.
        foreach ([
            '/admin' => 'Paiements (24h)',
            '/admin/organizations' => 'Organisations',
            '/admin/projects' => 'Projets',
            '/admin/payments' => 'Paiements',
            '/admin/webhook-deliveries/dead' => 'Livraisons de webhooks',
            '/admin/audit-log' => "Journal d'audit",
            '/admin/reconciliation/new' => 'Réconciliation groupée',
            '/admin/operators' => 'Opérateurs',
        ] as $path => $expectedText) {
            $response = $this->get($path);
            $response->assertOk();
            $response->assertSee($expectedText);
        }

        // The dashboard's own audit-log widget shows this operator's own
        // login trail — proof the AuditEntryView object-access fix works
        // under real data, not just an empty list.
        $this->get('/admin')->assertSee('operator.mfa_enrolled');

        $this->post('/admin/logout')->assertRedirect(route('admin.login.show'));

        // The session is gone — every page redirects back to login.
        $this->get('/admin')->assertRedirect(route('admin.login.show'));
    }

    #[Test]
    public function whatsapp_sessions_are_created_from_the_console_started_at_the_provider_and_registered(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);
        \Illuminate\Support\Facades\Http::fake([
            'waha.test/api/sessions' => \Illuminate\Support\Facades\Http::response(['name' => 'x', 'status' => 'STARTING'], 201),
            'waha.test/api/*/auth/qr' => \Illuminate\Support\Facades\Http::response('qr-bytes', 200, ['Content-Type' => 'image/png']),
            'waha.test/api/screenshot*' => \Illuminate\Support\Facades\Http::response('shot-bytes', 200, ['Content-Type' => 'image/png']),
            'waha.test/api/sessions/*' => \Illuminate\Support\Facades\Http::response(['name' => 'x', 'status' => 'WORKING'], 200),
            'waha.test/api/*/auth/request-code' => \Illuminate\Support\Facades\Http::response(['code' => 'ABCD-1234'], 200),
        ]);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Sessions Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Sessions Project'), actor: 'service:test', correlationId: null)->project;

        // Creation: started at the provider under the project-namespaced
        // name, and registered in the Core's own table.
        $this->post("/admin/projects/{$project->id}/whatsapp-sessions", ['session_ref' => 'ma-boutique'])
            ->assertRedirect(route('admin.projects.channel-sessions.index', ['projectId' => $project->id]));
        \Illuminate\Support\Facades\Http::assertSent(fn (\Illuminate\Http\Client\Request $r): bool => $r->url() === 'https://waha.test/api/sessions'
            && $r['name'] === $project->id.'_ma-boutique' && $r['start'] === true);
        $this->assertDatabaseHas('notification_channel_sessions', ['project_id' => $project->id, 'channel' => 'whatsapp', 'session_ref' => 'ma-boutique']);

        // Listed, with the three actions next to it.
        $this->get("/admin/projects/{$project->id}/whatsapp-sessions")
            ->assertOk()->assertSee('ma-boutique')->assertSee('Statut')->assertSee('Scanner (QR)')->assertSee("Capture d'écran", false)->assertSee("Code d'appairage", false);

        $this->get("/admin/projects/{$project->id}/whatsapp-sessions/ma-boutique/qr")->assertOk()->assertSee('data:image/png;base64,', false);
        $this->get("/admin/projects/{$project->id}/whatsapp-sessions/ma-boutique/screenshot")->assertOk()->assertSee('data:image/png;base64,', false);
        $this->post("/admin/projects/{$project->id}/whatsapp-sessions/ma-boutique/pairing-code", ['phone_number' => '+2376XXXXXXXX'])
            ->assertRedirect()->assertSessionHas('status');

        // Live state, read at the provider under the project-namespaced name.
        $this->get("/admin/projects/{$project->id}/whatsapp-sessions/ma-boutique/status")
            ->assertRedirect()->assertSessionHas('status');
        \Illuminate\Support\Facades\Http::assertSent(fn (\Illuminate\Http\Client\Request $r): bool => $r->method() === 'GET'
            && $r->url() === 'https://waha.test/api/sessions/'.$project->id.'_ma-boutique');

        // A session that was never registered has no QR: explicit message, not a provider call.
        $this->get("/admin/projects/{$project->id}/whatsapp-sessions/ghost/qr")->assertOk()->assertSee('is not registered for this project');

        // Audited.
        $this->get('/admin/audit-log')->assertSee('channel_session.created');

        // Deletion: removed at the provider AND unregistered; audited.
        \Illuminate\Support\Facades\Http::fake(['waha.test/api/sessions/*' => \Illuminate\Support\Facades\Http::response('', 200)]);
        $this->post("/admin/projects/{$project->id}/whatsapp-sessions/ma-boutique/delete")
            ->assertRedirect(route('admin.projects.channel-sessions.index', ['projectId' => $project->id]));
        \Illuminate\Support\Facades\Http::assertSent(fn (\Illuminate\Http\Client\Request $r): bool => $r->method() === 'DELETE'
            && $r->url() === 'https://waha.test/api/sessions/'.$project->id.'_ma-boutique');
        $this->assertDatabaseMissing('notification_channel_sessions', ['session_ref' => 'ma-boutique']);
        $this->get('/admin/audit-log')->assertSee('channel_session.deleted');
        $this->get("/admin/projects/{$project->id}/whatsapp-sessions")->assertDontSee('Supprimer « ma-boutique', false);

        // An invalid name never reaches the provider.
        $before = count(\Illuminate\Support\Facades\Http::recorded());
        $this->post("/admin/projects/{$project->id}/whatsapp-sessions", ['session_ref' => 'ma boutique:1'])->assertSessionHasErrors('session_ref');
        self::assertCount($before, \Illuminate\Support\Facades\Http::recorded());
    }

    #[Test]
    public function a_global_whatsapp_session_is_created_under_its_plain_name_with_no_owner(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);
        \Illuminate\Support\Facades\Http::fake(['waha.test/api/sessions' => \Illuminate\Support\Facades\Http::response(['name' => 'shared'], 201)]);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $this->post('/admin/whatsapp-sessions', ['session_ref' => 'shared'])->assertRedirect(route('admin.channel-sessions.index'));

        \Illuminate\Support\Facades\Http::assertSent(fn (\Illuminate\Http\Client\Request $r): bool => $r['name'] === 'shared');
        $this->assertDatabaseHas('notification_channel_sessions', ['project_id' => null, 'session_ref' => 'shared']);
        $this->get('/admin/whatsapp-sessions')->assertOk()->assertSee('shared')->assertSee('tous les projets');
    }

    #[Test]
    public function whatsapp_session_management_requires_its_permission(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->loginAndEnrollMfa(OperatorRole::ReadOnlyAuditor);

        $this->get('/admin/whatsapp-sessions')->assertForbidden();
        $this->post('/admin/whatsapp-sessions', ['session_ref' => 'x'])->assertForbidden();
        $this->post('/admin/whatsapp-sessions/x/delete')->assertForbidden();
    }

    #[Test]
    public function an_operator_without_the_required_permission_gets_a_403_not_a_200(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->loginAndEnrollMfa(OperatorRole::ReadOnlyAuditor);

        // Read-only/Auditor has projects:read but not
        // payments:reconcile:write — before this fix, every web
        // controller except the dashboard skipped the permission check
        // entirely, so this would have returned 200 for any
        // authenticated operator regardless of role.
        $this->get('/admin/projects')->assertOk();
        $this->get('/admin/reconciliation/new')->assertForbidden();
        $this->get('/admin/operators')->assertForbidden();
        $this->get('/admin/operators/create')->assertForbidden();
    }

    #[Test]
    public function a_platform_admin_creates_and_revokes_an_operator_through_the_web_console(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $email = uniqid('new-op-', true).'@example.com';

        $store = $this->post('/admin/operators', [
            'email' => $email,
            'name' => 'New Operator',
            'role' => OperatorRole::SupportOperator->value,
        ]);
        $store->assertRedirect(route('admin.operators.index'));

        $index = $this->get('/admin/operators');
        $index->assertOk();
        $index->assertSee($email);
        $index->assertSee('Mot de passe à usage unique');

        $created = app(OperatorRepository::class)->findByEmail($email);
        assert($created instanceof Operator);

        $this->post("/admin/operators/{$created->id}/revoke")
            ->assertRedirect(route('admin.operators.index'));

        $this->get('/admin/operators')->assertSee('Révoqué');
    }

    #[Test]
    public function a_malformed_organization_id_on_the_web_projects_page_redirects_back_with_errors_not_a_500(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        // A web request that fails validation gets Laravel's normal
        // redirect-back-with-$errors behaviour (bootstrap/app.php's
        // exception handler, review finding, Lot 6 follow-up) — never a
        // raw problem+json body, and never the uncaught QueryException a
        // malformed UUID used to cause reaching Postgres unvalidated.
        $response = $this->get('/admin/projects?organization_id=not-a-uuid');
        $response->assertStatus(302);
        $response->assertSessionHasErrors('organization_id');
    }

    #[Test]
    public function creating_a_project_through_the_web_console_shows_both_auto_provisioned_keys_once(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Web Console Org'),
            actor: 'service:test',
            correlationId: null,
        );

        $store = $this->post('/admin/projects', [
            'organization_id' => $organization->id,
            'name' => 'Web Console Project',
        ]);
        $store->assertRedirect();

        $location = $store->headers->get('Location');
        self::assertIsString($location);

        // The one-shot keys are flashed to the very next request only.
        $show = $this->get($location);
        $show->assertOk();
        $show->assertSee('pk_');

        $again = $this->get($location);
        $again->assertOk();
        $again->assertDontSee('pk_');
    }

    #[Test]
    public function registering_a_webhook_endpoint_through_the_web_console_expands_categories_into_event_types(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Webhook Category Org'),
            actor: 'service:test',
            correlationId: null,
        );
        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Webhook Category Project'),
            actor: 'service:test',
            correlationId: null,
        )->project;

        $store = $this->post("/admin/projects/{$project->id}/webhook-endpoints", [
            // `example.com` (not `example.test`) — this now goes through
            // `SafeOutboundUrlRule` (fixed alongside this test: the admin
            // console form used to skip SSRF validation the JSON API
            // already had), which resolves the host over real DNS;
            // `.test` is reserved to never resolve at all (RFC 2606),
            // unlike `.com`/`.org`/`.net`, IANA's stable, always-resolving
            // documentation domains.
            'url' => 'https://example.com/webhooks/core',
            'categories' => ['payments', 'wallets'],
        ]);
        $store->assertRedirect(route('admin.projects.webhook-endpoints.create', ['projectId' => $project->id]));

        $endpoints = app(ListWebhookEndpoints::class)->list(
            new ListWebhookEndpointsCommand($project->id),
        );
        self::assertCount(1, $endpoints);
        self::assertSame(
            ['payment.status_changed', 'wallet.credited', 'wallet.debited'],
            $endpoints[0]->events,
        );

        $again = $this->get(route('admin.projects.webhook-endpoints.create', ['projectId' => $project->id]));
        $again->assertOk();
        $again->assertSee('Paiements');
    }

    #[Test]
    public function a_webhook_endpoint_can_be_updated_deactivated_and_reactivated_from_the_web_console(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Webhook Manage Org'),
            actor: 'service:test',
            correlationId: null,
        );
        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Webhook Manage Project'),
            actor: 'service:test',
            correlationId: null,
        )->project;

        $endpoint = app(RegisterWebhookEndpoint::class)->register(
            new RegisterWebhookEndpointCommand($project->id, 'https://example.com/hooks/original', ['payment.status_changed']),
        );

        // Update: review finding — there used to be no way to change an
        // endpoint's URL or events at all once registered.
        $update = $this->put("/admin/projects/{$project->id}/webhook-endpoints/{$endpoint->id}", [
            'url' => 'https://example.com/hooks/updated',
            'categories' => ['wallets'],
        ]);
        $update->assertRedirect(route('admin.projects.webhook-endpoints.create', ['projectId' => $project->id]));

        $afterUpdate = app(ListWebhookEndpoints::class)->list(new ListWebhookEndpointsCommand($project->id));
        self::assertCount(1, $afterUpdate);
        self::assertSame('https://example.com/hooks/updated', $afterUpdate[0]->url);
        self::assertSame(['wallet.credited', 'wallet.debited'], $afterUpdate[0]->events);
        self::assertTrue($afterUpdate[0]->active);

        // Deactivate: review finding — the admin console had no button
        // for this at all (the port existed, unreachable from the UI),
        // and the listing used to query active-only, so a deactivated
        // endpoint would have simply vanished instead of showing inactive.
        $deactivate = $this->post("/admin/projects/{$project->id}/webhook-endpoints/{$endpoint->id}/deactivate");
        $deactivate->assertRedirect(route('admin.projects.webhook-endpoints.create', ['projectId' => $project->id]));

        $afterDeactivate = app(ListWebhookEndpoints::class)->list(new ListWebhookEndpointsCommand($project->id));
        self::assertCount(1, $afterDeactivate, 'a deactivated endpoint must still be listed, not vanish');
        self::assertFalse($afterDeactivate[0]->active);

        $listPage = $this->get(route('admin.projects.webhook-endpoints.create', ['projectId' => $project->id]));
        $listPage->assertOk();
        $listPage->assertSee('inactif');

        // Reactivate.
        $activate = $this->post("/admin/projects/{$project->id}/webhook-endpoints/{$endpoint->id}/activate");
        $activate->assertRedirect(route('admin.projects.webhook-endpoints.create', ['projectId' => $project->id]));

        $afterActivate = app(ListWebhookEndpoints::class)->list(new ListWebhookEndpointsCommand($project->id));
        self::assertTrue($afterActivate[0]->active);
    }

    #[Test]
    public function registering_a_provider_application_twice_rotates_it_and_the_second_time_is_audited_as_a_rotation(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Provider App Org'),
            actor: 'service:test',
            correlationId: null,
        );
        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Provider App Project'),
            actor: 'service:test',
            correlationId: null,
        )->project;

        $listingUrl = "/admin/projects/{$project->id}/provider-applications/create";

        $first = $this->post("/admin/projects/{$project->id}/provider-applications", [
            'provider' => 'my-coolpay',
            'public_key' => 'pub-key-first',
            'private_key' => 'secret-first',
        ]);
        $first->assertRedirect($listingUrl);
        $this->get('/admin/audit-log')->assertSee('provider_application.registered');

        // Editing keeps the stored private key when the field is left empty.
        $second = $this->post("/admin/projects/{$project->id}/provider-applications", [
            'provider' => 'my-coolpay',
            'public_key' => 'pub-key-second',
            'private_key' => '',
        ]);
        $second->assertRedirect($listingUrl);
        $this->get('/admin/audit-log')->assertSee('provider_application.rotated');

        $stored = app(\Core\Payment\Domain\Port\ProviderApplicationRepository::class)->findByProjectAndProvider($project->id, 'my-coolpay');
        self::assertNotNull($stored);
        self::assertSame('pub-key-second', $stored->publicKey);
        self::assertSame('secret-first', $stored->privateKey);

        $listing = $this->get($listingUrl);
        $listing->assertOk()->assertSee('pub-key-second')->assertSee('Modifier');

        // The edit page is reachable from the listing.
        $this->get("/admin/projects/{$project->id}/provider-applications/{$stored->id}/edit")
            ->assertOk()
            ->assertSee("Modifier l'application my-coolpay");
    }

    #[Test]
    public function reusing_a_public_key_of_another_project_gives_a_clear_message_not_a_sql_error(): void
    {
        // The reported bug: editing an application answered with the raw
        // PostgreSQL "duplicate key" message.
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Dup Org'), actor: 'service:test', correlationId: null);
        $owner = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet Propriétaire'), actor: 'service:test', correlationId: null)->project;
        $other = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Autre Projet'), actor: 'service:test', correlationId: null)->project;

        $this->post("/admin/projects/{$owner->id}/provider-applications", [
            'provider' => 'my-coolpay', 'public_key' => 'shared-pub', 'private_key' => 's1',
        ])->assertRedirect();

        $this->from("/admin/projects/{$other->id}/provider-applications/create")
            ->post("/admin/projects/{$other->id}/provider-applications", [
                'provider' => 'my-coolpay', 'public_key' => 'shared-pub', 'private_key' => 's2',
            ])
            ->assertRedirect("/admin/projects/{$other->id}/provider-applications/create")
            ->assertSessionHasErrors(['public_key' => 'Cette clé publique est déjà utilisée par le projet « Projet Propriétaire ». Une application my-coolpay ne peut servir qu\'un seul projet : c\'est elle qui permet au Core d\'attribuer chaque callback au bon projet. Créez une application distincte chez le provider, ou modifiez celle du projet « Projet Propriétaire ».']);

        $page = $this->get("/admin/projects/{$other->id}/provider-applications/create");
        $page->assertDontSee('duplicate key')->assertDontSee('SQLSTATE');
    }

    #[Test]
    public function replaying_a_dead_webhook_delivery_from_the_web_console_is_audited(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $projectId = (string) Str::uuid();
        $endpoint = app(RegisterWebhookEndpoint::class)->register(
            new RegisterWebhookEndpointCommand($projectId, 'https://example.com/hook', ['*']),
        );

        $deadDeliveryId = (string) Str::uuid();
        app(WebhookDeliveryRepository::class)->create(new WebhookDelivery(
            id: $deadDeliveryId,
            endpointId: $endpoint->id,
            projectId: $projectId,
            eventId: (string) Str::uuid(),
            eventType: 'payment.status_changed',
            payload: ['foo' => 'bar'],
            status: WebhookDeliveryStatus::Dead,
            attempts: 8,
            nextRetryAt: null,
            lastAttemptAt: new DateTimeImmutable,
            lastError: 'connection timed out',
            deliveredAt: null,
        ));

        $this->post("/admin/webhook-deliveries/{$deadDeliveryId}/replay")
            ->assertRedirect(route('admin.webhook-deliveries.dead.web'));

        // Security review finding: replaying from the web console used
        // to leave no audit trail at all, unlike the identical JSON API
        // action.
        $this->get('/admin/audit-log')->assertSee('webhook_delivery.replayed');
    }

    #[Test]
    public function the_webhook_deliveries_page_shows_the_request_envelope_and_the_endpoints_response(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $projectId = (string) Str::uuid();
        $endpoint = app(RegisterWebhookEndpoint::class)->register(
            new RegisterWebhookEndpointCommand($projectId, 'https://example.com/hook', ['*']),
        );

        $delivery = new WebhookDelivery(
            id: (string) Str::uuid(),
            endpointId: $endpoint->id,
            projectId: $projectId,
            eventId: (string) Str::uuid(),
            eventType: 'payment.status_changed',
            payload: ['status' => 'succeeded'],
            status: WebhookDeliveryStatus::Pending,
            attempts: 0,
            nextRetryAt: null,
            lastAttemptAt: null,
            lastError: null,
            deliveredAt: null,
        );
        app(WebhookDeliveryRepository::class)->create($delivery);
        app(WebhookDeliveryRepository::class)->save(
            $delivery->recordFailure('Endpoint responded with HTTP 500.', new DateTimeImmutable, null, 500, '{"error":"boom"}'),
        );

        $page = $this->get('/admin/webhook-deliveries/dead');
        $page->assertOk();
        // The request body is reconstructed from eventId/eventType/payload
        // (the exact shape HttpWebhookSender sends), not a stored copy.
        // Blade's `{{ }}` HTML-escapes the JSON — assertSee's default
        // `$escape = true` escapes the needle the same way before matching.
        $page->assertSee('"type": "payment.status_changed"');
        $page->assertSee('"status": "succeeded"');
        $page->assertSee('HTTP 500');
        $page->assertSee('{"error":"boom"}');
    }

    #[Test]
    public function amounts_in_a_zero_decimal_currency_display_correctly_not_divided_by_100(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('XAF Display Org'),
            actor: 'service:test',
            correlationId: null,
        );
        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'XAF Display Project'),
            actor: 'service:test',
            correlationId: null,
        )->project;

        $paymentId = (string) Str::uuid();
        app(PaymentRepository::class)->create(new Payment(
            id: $paymentId,
            projectId: $project->id,
            channel: 'mtn_momo',
            providerCode: 'my-coolpay',
            idempotencyKey: (string) Str::uuid(),
            providerTransactionRef: (string) Str::uuid(),
            amount: 100,
            currency: 'XAF',
            status: PaymentStatus::Succeeded,
            requiresAction: false,
            actionHint: null,
            paymentUrl: null,
            errorMessage: null,
            returnUrl: null,
            walletRecipientExternalRef: null,
        ));

        $walletId = (string) Str::uuid();
        $partyId = (string) Str::uuid();
        app(WalletRepository::class)->save(new Wallet($walletId, $project->id, $partyId, 'XAF', 'active'));
        app(WalletBalanceRepository::class)->initialize($walletId, 'XAF');
        app(WalletBalanceRepository::class)->save(new WalletBalance(
            $walletId,
            Money::ofMinor(100, 'XAF'),
            Money::zero('XAF'),
            1,
        ));

        // 100 XAF minor units is 100 XAF (0 decimal digits) — the reported
        // bug divided by 100 unconditionally and displayed "1" or "1.00".
        $this->get('/admin/payments')->assertOk()->assertDontSee('1.00')->assertSee('100');
        $this->get("/admin/payments/{$paymentId}?project_id={$project->id}")->assertOk()->assertDontSee('1.00')->assertSee('100');
        $this->get("/admin/projects/{$project->id}/wallets")->assertOk()->assertDontSee('1.00')->assertSee('100');
    }

    #[Test]
    public function a_wallets_ledger_page_shows_the_double_entry_postings_that_built_its_balance(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Ledger Display Org'),
            actor: 'service:test',
            correlationId: null,
        );
        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Ledger Display Project'),
            actor: 'service:test',
            correlationId: null,
        )->project;

        $partyId = (string) Str::uuid();
        $balance = app(CreditWallet::class)->credit(new CreditWalletCommand(
            projectId: $project->id,
            partyId: $partyId,
            amountMinor: 100,
            currency: 'XAF',
            externalRef: 'payment-42',
            idempotencyKey: (string) Str::uuid(),
        ));

        $page = $this->get("/admin/projects/{$project->id}/wallets/{$balance->walletId}/ledger");
        $page->assertOk();
        $page->assertSee('wallet_credit');
        $page->assertSee('payment-42');
        $page->assertSee('crédit');
        // 100 XAF (0 decimal digits), never "1.00" — same money-formatting
        // rule as the wallets/payments pages.
        $page->assertDontSee('1.00');
        $page->assertSee('+100');

        $again = $this->get("/admin/projects/{$project->id}/wallets/".Str::uuid().'/ledger');
        $again->assertNotFound();
    }

    #[Test]
    public function triggering_reconciliation_from_the_web_console_is_audited(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        // No stuck payments seeded — proves the audit trail without
        // needing a fake HTTP provider call, mirroring the JSON API's
        // equivalent "nothing stuck" test.
        $this->post('/admin/reconciliation')
            ->assertRedirect(route('admin.reconciliation.new'));

        $this->get('/admin/audit-log')->assertSee('payment_reconciliation.triggered');
    }

    #[Test]
    public function the_public_api_docs_are_reachable_without_any_operator_session(): void
    {
        // `/docs/api` is the third-party integration surface (ADR-0049) —
        // a Project integrator has no operator credentials at all, so
        // Scramble's own RestrictedDocsAccess middleware (which only lets
        // APP_ENV=local through automatically) must find `viewApiDocs`
        // permissive here regardless of environment. User-reported: this
        // 403'd on the first real production deployment, since the Gate
        // it checks outside `local` was never defined.
        $this->get('/docs/api')->assertOk();
    }

    #[Test]
    public function the_admin_docs_require_an_operator_session_same_as_any_other_console_page(): void
    {
        // No session at all: ResolveOperatorSession (via the
        // `operator.session` middleware attached to this route) redirects
        // to login exactly like any other admin console page — never a
        // raw 403, that's Scramble's own RestrictedDocsAccess guarding
        // routes with no operator-auth concept, not this one's real gate.
        $this->get('/docs/admin')->assertRedirect(route('admin.login.show'));

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $this->get('/docs/admin')->assertOk();
    }

    #[Test]
    public function a_browser_session_can_reach_v1_admin_routes_without_a_bearer_header(): void
    {
        // Review finding: an operator who is logged in via the web console
        // used to get a 401 "A Bearer operator token is required." every
        // time they navigated to a `/v1/admin/*` URL (e.g. the Scramble
        // docs UI, a link shared in chat, manual address-bar typing). The
        // session held the plaintext bearer token server-side but the
        // API middleware never read it. Now the `operator.auth` middleware
        // falls back to that session when no `Authorization: Bearer …`
        // header is present, and the route group wires in the session
        // middleware so the fallback is reachable.
        $this->withoutMiddleware(PreventRequestForgery::class);

        $created = app(CreateOperator::class)->create(
            new CreateOperatorCommand('session-fallback@example.com', 'Session Fallback', OperatorRole::PlatformAdmin->value),
            actor: 'service:test',
            correlationId: null,
        );

        $this->post('/admin/login', [
            'email' => 'session-fallback@example.com',
            'password' => $created->plaintextPassword,
        ])->assertRedirect(route('admin.mfa.enroll.show'));

        $enroll = $this->get('/admin/mfa/enroll');
        $secret = $this->extractSecret($enroll);
        $challengeToken = $this->extractHiddenField($enroll, 'challenge_token');
        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $challengeToken,
            'code' => $code,
        ])->assertRedirect(route('admin.dashboard'));

        // Browser navigation (Accept: text/html, no Bearer header) to a
        // /v1/admin/* endpoint now succeeds — the operator auth middleware
        // resolves the operator from the encrypted server-side session.
        // The Scramble docs UI ("Try it out") and any operator who pastes a
        // v1 URL into the address bar hit this exact path.
        $this->get('/v1/admin/operators', ['Accept' => 'text/html'])
            ->assertOk()
            ->assertJsonPath('data.0.email', 'session-fallback@example.com');

        // JSON client with a Bearer header still authenticates the same
        // way it always did — the fallback is opt-in, not a replacement.
        $bearer = $created->plaintextPassword === ''
            ? null
            : $this->extractOperatorBearerToken();
        if ($bearer !== null) {
            $this->getJson('/v1/admin/operators', ['Authorization' => "Bearer {$bearer}"])
                ->assertOk();
        }
    }

    #[Test]
    public function a_project_has_exactly_one_key_that_can_be_regenerated_and_revoked(): void
    {
        // ADR-0066 — one key per project.
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Keys Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Keys Project'), actor: 'service:test', correlationId: null)->project;
        $keys = static fn (): array => app(\Core\Identity\Contracts\ListApiKeys::class)->list(new \Core\Identity\Contracts\ListApiKeysCommand($project->id));

        // Auto-provisioned at creation.
        self::assertCount(1, $keys());
        $this->get("/admin/projects/{$project->id}/api-keys/create")->assertOk()->assertSee('Clé API du projet')->assertSee('Régénérer')->assertDontSee('Sandbox');

        // Generating a second key is refused with a clear message.
        $this->post("/admin/projects/{$project->id}/api-keys", ['mode' => 'generate'])
            ->assertSessionHasErrors('mode');
        self::assertCount(1, $keys());

        // Regenerating replaces it: still exactly one key, a new prefix.
        $old = $keys()[0];
        $this->post("/admin/projects/{$project->id}/api-keys", ['mode' => 'regenerate'])->assertRedirect();
        $afterRegenerate = $keys();
        self::assertCount(1, $afterRegenerate);
        self::assertNotSame($old->prefix, $afterRegenerate[0]->prefix);

        // Revoking deletes; a key can then be generated again.
        $this->post("/admin/projects/{$project->id}/api-keys/{$afterRegenerate[0]->id}/revoke")->assertRedirect();
        self::assertCount(0, $keys());
        $this->post("/admin/projects/{$project->id}/api-keys", ['mode' => 'generate'])->assertRedirect();
        self::assertCount(1, $keys());
    }

    #[Test]
    public function the_payment_page_shows_its_journey(): void
    {
        // ADR-0059 — the "Parcours" section lists every recorded step.
        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Journey Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Journey Project'), actor: 'service:test', correlationId: null)->project;

        $paymentId = (string) Str::uuid();
        app(PaymentRepository::class)->create(new Payment(
            id: $paymentId,
            projectId: $project->id,
            channel: 'mtn_momo',
            providerCode: 'my-coolpay',
            idempotencyKey: (string) Str::uuid(),
            providerTransactionRef: (string) Str::uuid(),
            amount: 500,
            currency: 'XAF',
            status: PaymentStatus::Succeeded,
            requiresAction: false,
            actionHint: null,
            paymentUrl: null,
            errorMessage: null,
            returnUrl: null,
            walletRecipientExternalRef: null,
        ));

        $journey = app(\Core\Payment\Application\Service\PaymentJourney::class);
        $journey->record($paymentId, $project->id, \Core\Payment\Domain\Model\PaymentTimelineEntryType::ApiRequestReceived, 'Requête POST /v1/payments/payin reçue de l\'intégrateur', ['body' => ['amount' => 500]]);
        $journey->record($paymentId, $project->id, \Core\Payment\Domain\Model\PaymentTimelineEntryType::StatusChanged, 'Statut pending → succeeded (callback du provider) — statut final', ['to' => 'succeeded', 'is_final' => true]);

        $this->get("/admin/payments/{$paymentId}?project_id={$project->id}")
            ->assertOk()
            ->assertSee('Parcours')
            ->assertSee('Requête reçue')
            ->assertSee('Statut pending → succeeded (callback du provider) — statut final');
    }

    #[Test]
    public function a_projects_notifications_are_listed_and_their_detail_shows_content_variables_and_attempts(): void
    {
        // ADR-0062 — everything the Core knows about a notification.
        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Notif Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Notif Project'), actor: 'service:test', correlationId: null)->project;

        $notificationId = (string) Str::uuid();
        app(\Core\Notification\Domain\Port\NotificationRepository::class)->create(new \Core\Notification\Domain\Model\Notification(
            id: $notificationId,
            projectId: $project->id,
            channel: 'whatsapp',
            toPartyRef: 'customer-42',
            contentType: 'text',
            idempotencyKey: 'order-42-confirmation',
            status: \Core\Notification\NotificationStatus::Failed,
            providerCode: null,
            providerMessageId: null,
            errorMessage: 'Session not linked',
            content: ['text' => 'Votre commande est confirmée'],
            variables: ['phone_number' => '237690000000', 'session' => 'shop'],
        ));
        app(\Core\Notification\Domain\Port\NotificationDeliveryRepository::class)->record(
            $notificationId, 'waha', null, \Core\Notification\NotificationStatus::Failed, 1, 'Session not linked',
        );

        $this->get("/admin/projects/{$project->id}/notifications")
            ->assertOk()
            ->assertSee(substr($notificationId, 0, 8))
            ->assertSee('customer-42');

        $this->get("/admin/projects/{$project->id}/notifications/{$notificationId}")
            ->assertOk()
            ->assertSee('Votre commande est confirmée')
            ->assertSee('237690000000')
            ->assertSee('order-42-confirmation')
            ->assertSee('waha')
            ->assertSee('Session not linked')
            ->assertSee('final');

        // Scoped to the Project in the URL.
        $other = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Other'), actor: 'service:test', correlationId: null)->project;
        $this->get("/admin/projects/{$other->id}/notifications/{$notificationId}")->assertNotFound();
    }

    #[Test]
    public function notification_content_older_than_the_retention_period_is_redacted(): void
    {
        // ADR-0062 / D5 — content and phone number kept 90 days only.
        $repository = app(\Core\Notification\Domain\Port\NotificationRepository::class);
        $old = (string) Str::uuid();
        $recent = (string) Str::uuid();
        foreach ([$old, $recent] as $id) {
            $repository->create(new \Core\Notification\Domain\Model\Notification(
                id: $id, projectId: (string) Str::uuid(), channel: 'whatsapp', toPartyRef: 'c', contentType: 'text',
                idempotencyKey: $id, status: \Core\Notification\NotificationStatus::Sent, providerCode: 'waha',
                providerMessageId: null, errorMessage: null,
                content: ['text' => 'hello'], variables: ['phone_number' => '237690000000'],
            ));
        }
        \Illuminate\Support\Facades\DB::table('notifications')->where('id', $old)->update(['created_at' => now()->subDays(91)]);

        self::assertSame(0, \Illuminate\Support\Facades\Artisan::call('notification:redact-expired'));

        $redacted = $repository->find($old);
        $kept = $repository->find($recent);
        self::assertNotNull($redacted);
        self::assertNotNull($kept);
        self::assertSame([], $redacted->variables);
        self::assertSame([], $redacted->content);
        self::assertSame(['phone_number' => '237690000000'], $kept->variables);
    }

    #[Test]
    public function a_webhook_delivery_has_its_own_detail_page(): void
    {
        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $projectId = (string) Str::uuid();
        $endpoint = app(RegisterWebhookEndpoint::class)->register(
            new RegisterWebhookEndpointCommand($projectId, 'https://example.com/hook-detail', ['*']),
        );
        $delivery = new WebhookDelivery(
            id: (string) Str::uuid(),
            endpointId: $endpoint->id,
            projectId: $projectId,
            eventId: (string) Str::uuid(),
            eventType: 'payment.status_changed',
            payload: ['status' => 'failed'],
            status: WebhookDeliveryStatus::Pending,
            attempts: 0,
            nextRetryAt: null,
            lastAttemptAt: null,
            lastError: null,
            deliveredAt: null,
        );
        app(WebhookDeliveryRepository::class)->create($delivery);

        $this->get("/admin/webhook-deliveries/{$delivery->id}")
            ->assertOk()
            ->assertSee($delivery->id)
            ->assertSee('https://example.com/hook-detail')
            ->assertSee('payment.status_changed');

        $this->get('/admin/webhook-deliveries/'.Str::uuid())->assertRedirect(route('admin.webhook-deliveries.dead.web'));
    }

    /**
     * Pulls the operator's plaintext bearer token out of the encrypted
     * session that `AuthController::completeLogin()` wrote at login time.
     * The session middleware doesn't expose a public getter, but
     * `Session::get()` does — and the key is a single source of truth
     * between `ResolveOperatorMiddleware` and `AuthController`.
     */
    private function extractOperatorBearerToken(): ?string
    {
        $value = \Illuminate\Support\Facades\Session::get(
            \Core\Identity\Presentation\Http\Middleware\ResolveOperatorMiddleware::SESSION_KEY,
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Completes the login+MFA flow, leaving the authenticated session on
     * `$this` for subsequent calls within the same test method.
     */
    private function loginAndEnrollMfa(OperatorRole $role): void
    {
        $created = app(CreateOperator::class)->create(
            new CreateOperatorCommand(uniqid('op-', true).'@example.com', 'Ops', $role->value),
            actor: 'service:test',
            correlationId: null,
        );

        $operator = app(OperatorRepository::class)->find($created->operatorId);
        assert($operator instanceof Operator);

        $login = $this->post('/admin/login', [
            'email' => $operator->email,
            'password' => $created->plaintextPassword,
        ]);
        $login->assertRedirect(route('admin.mfa.enroll.show'));

        $enroll = $this->get('/admin/mfa/enroll');
        $secret = $this->extractSecret($enroll);
        $challengeToken = $this->extractHiddenField($enroll, 'challenge_token');
        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $challengeToken,
            'code' => $code,
        ])->assertRedirect(route('admin.dashboard'));
    }

    /**
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    private function extractSecret(TestResponse $response): string
    {
        $content = $this->htmlBody($response);

        if (preg_match('/name="secret"\s+value="([A-Z2-7]+)"/', $content, $matches) !== 1
            && preg_match('/<code[^>]*>([A-Z2-7]{16,32})<\/code>/', $content, $matches) !== 1) {
            self::fail('Could not find the TOTP secret in the MFA enroll page.');
        }

        return $matches[1];
    }

    /**
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    private function extractHiddenField(TestResponse $response, string $name): string
    {
        $content = $this->htmlBody($response);

        if (preg_match('/name="'.preg_quote($name, '/').'"\s+value="([^"]+)"/', $content, $matches) !== 1) {
            self::fail("Could not find hidden field {$name} in the response.");
        }

        return $matches[1];
    }

    /**
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    private function htmlBody(TestResponse $response): string
    {
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $response->getContent();

        return is_string($content) ? $content : '';
    }
}
