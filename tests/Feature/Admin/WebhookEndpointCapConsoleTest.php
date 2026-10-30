<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * Audit 2026-10-29 #17 / G-12 — the ceiling of active webhook endpoints a
 * project may hold, seen from the operator console.
 *
 * The console answers a refused write the way it answers any invalid
 * input: the operator stays on the page, with the reason. A refusal that
 * crashed into a 500 would leave the operator thinking the endpoint had
 * been registered.
 *
 * The ceiling is lowered here so the test stays readable — the value is a
 * configuration, not a constant of the code.
 */
final class WebhookEndpointCapConsoleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registering_past_the_project_ceiling_is_refused_with_a_readable_error(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['webhook.max_active_endpoints_per_project' => 1]);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Webhook Cap Org'),
            actor: 'service:test',
            correlationId: null,
        );
        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Webhook Cap Project'),
            actor: 'service:test',
            correlationId: null,
        )->project;

        $this->post("/admin/projects/{$project->id}/webhook-endpoints", [
            'url' => 'https://example.com/webhooks/core',
            'categories' => ['payments'],
        ])->assertOk();

        $refused = $this->post("/admin/projects/{$project->id}/webhook-endpoints", [
            'url' => 'https://example.com/webhooks/second',
            'categories' => ['payments'],
        ]);

        $refused->assertRedirect();
        $refused->assertSessionHasErrors('url');

        // The refused registration created nothing.
        self::assertSame(1, DB::table('webhook_endpoints')->where('project_id', $project->id)->count());
    }

    #[Test]
    public function a_project_below_the_ceiling_registers_normally(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['webhook.max_active_endpoints_per_project' => 2]);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Webhook Cap Org'),
            actor: 'service:test',
            correlationId: null,
        );
        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Webhook Cap Project'),
            actor: 'service:test',
            correlationId: null,
        )->project;

        $this->post("/admin/projects/{$project->id}/webhook-endpoints", [
            'url' => 'https://example.com/webhooks/core',
            'categories' => ['payments'],
        ])->assertOk();

        $this->post("/admin/projects/{$project->id}/webhook-endpoints", [
            'url' => 'https://example.com/webhooks/second',
            'categories' => ['payments'],
        ])->assertOk();

        self::assertSame(2, DB::table('webhook_endpoints')->where('project_id', $project->id)->count());
    }

    private function loginAndEnrollMfa(OperatorRole $role): void
    {
        $created = OperatorFactory::create(uniqid('op-', true).'@example.com', $role);

        $operator = app(OperatorRepository::class)->find($created);
        assert($operator instanceof Operator);

        $login = $this->post('/admin/login', [
            'email' => $operator->email,
            'password' => OperatorFactory::PASSWORD,
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
