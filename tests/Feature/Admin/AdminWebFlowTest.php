<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Application\Dto\CreateOperatorCommand;
use Core\Identity\Application\UseCase\CreateOperator;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $created = app(CreateOperator::class)(
            new CreateOperatorCommand('webflow@example.com', 'Web Flow', OperatorRole::PlatformAdmin),
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
            '/admin' => 'Payments (24h)',
            '/admin/organizations' => 'Organizations',
            '/admin/projects' => 'Projects',
            '/admin/payments' => 'Payments',
            '/admin/webhook-deliveries/dead' => 'Webhook DLQ',
            '/admin/audit-log' => 'Audit log',
            '/admin/reconciliation/new' => 'Trigger reconciliation',
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

    /**
     * Completes the login+MFA flow, leaving the authenticated session on
     * `$this` for subsequent calls within the same test method.
     */
    private function loginAndEnrollMfa(OperatorRole $role): void
    {
        $created = app(CreateOperator::class)(
            new CreateOperatorCommand(uniqid('op-', true).'@example.com', 'Ops', $role),
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
