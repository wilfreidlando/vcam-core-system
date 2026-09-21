<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\CreateOperator;
use Core\Identity\Contracts\CreateOperatorCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
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
            '/admin/webhook-deliveries/dead' => 'Webhooks en échec',
            '/admin/audit-log' => "Journal d'audit",
            '/admin/reconciliation/new' => 'Déclencher la réconciliation',
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
