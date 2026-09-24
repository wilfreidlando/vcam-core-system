<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Support\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Lives under `Tests\Integration` (app-level), not `Core\Notification\Tests\...`:
 * this scenario deliberately orchestrates Identity fixtures directly,
 * which `notification`'s own test suite cannot do (G3c).
 *
 * `packages/notification/tests/Integration/Infrastructure/Provider/Waha/WahaAdapterTest.php`
 * constructs `WahaAdapter` by hand and never exercises the real
 * container binding `NotificationServiceProvider::registerWahaAdapter()`
 * wires from `config/providers.php` — meaning the full path from a real
 * HTTP request through `SendTextNotificationRequest::validated()`,
 * `SendTextNotificationUseCase`, `NotificationSendCascade` and finally
 * the container-resolved `WahaAdapter` had never actually run end to
 * end. User-reported: a production `POST /v1/notifications/text` with
 * `variables: {"phone_number": "+237..."}` — the exact documented shape
 * — came back `status: "failed"`, `error_message: "... is missing
 * variables['phone_number']"`, even though the value was genuinely
 * present in the request body.
 */
final class SendTextNotificationHttpFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_whatsapp_text_notification_with_phone_number_reaches_the_provider_with_it(): void
    {
        // Real config path is `providers.notification.whatsapp_waha.*`,
        // NOT `providers.whatsapp_waha.*` (see config/providers.php) — an
        // earlier version of this test set the wrong, flat key, which
        // silently did nothing: the real WAHA_BASE_URL from .env stayed
        // in effect, Http::fake() only matched the (unreached) fake URL,
        // and the unmatched request went out for real. preventStrayRequests()
        // turns that exact mistake into a hard failure instead of a live
        // side effect against a real external service.
        Http::preventStrayRequests();

        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);

        Http::fake([
            'waha.test/api/sendText' => Http::response(['id' => 'msg-1'], 201),
        ]);

        $token = $this->issueTestApiKey();

        $response = $this->postJson('/v1/notifications/text', [
            'channel' => 'whatsapp',
            'to_party_ref' => 'customer-42',
            'text' => 'Hello Marie, your order #1234 is confirmed.',
            'variables' => ['phone_number' => '+237657467941'],
            'client_reference' => 'client-msg-abc123',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'sent');

        // If this fails while the assertion above passes, `variables`
        // reached the use case correctly but got mangled before the
        // provider call — narrows the failure to a different layer than
        // "the phone number was silently dropped somewhere upstream".
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://waha.test/api/sendText'
            && $r['chatId'] === '237657467941@c.us');
    }

    private function issueTestApiKey(string $scope = 'notifications:write'): string
    {
        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Test Org'),
            actor: 'service:test',
            correlationId: null,
        );

        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Test Project'),
            actor: 'service:test',
            correlationId: null,
        )->project;

        $issued = app(IssueApiKey::class)->issue(
            new IssueApiKeyCommand($project->id, Environment::Sandbox, [$scope]),
            actor: 'service:test',
            correlationId: null,
        );

        return $issued->plaintextToken;
    }
}
