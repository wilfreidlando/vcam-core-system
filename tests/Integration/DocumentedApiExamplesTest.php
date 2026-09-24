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
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Core\Support\Environment;
use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * User-reported: "presque tout ça ne marche pas" (almost none of it
 * works) when testing /docs/api's own documented examples. Every field
 * value in every test below is copied verbatim from
 * `openapi/v1.json`'s `components.schemas.*.properties.*.examples` —
 * the exact strings an integrator reading the docs would paste. Every
 * outbound provider call is faked and `Http::preventStrayRequests()` is
 * always on first, so nothing here can repeat the earlier session's
 * mistake of hitting a real external service.
 *
 * Lives under `Tests\Integration` (app-level), not any one module's own
 * suite: it deliberately spans payment/wallet/notification/webhook/
 * identity fixtures, which a single module's own tests cannot do (G3c).
 */
final class DocumentedApiExamplesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function get_whoami_matches_its_documented_response_shape(): void
    {
        [$token] = $this->authenticatedProject();

        $this->getJson('/v1/whoami', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonStructure(['project_id', 'organization_id', 'environment', 'scopes']);
    }

    #[Test]
    public function post_payments_paylink_with_the_full_documented_example(): void
    {
        [$token, $projectId] = $this->authenticatedProject();
        $this->registerMyCoolPayApplication($projectId, 'pub-doc-paylink');
        $this->creditWalletExternalRef($projectId, 'wallet-customer-42');

        Http::fake([
            'my-coolpay.com/api/pub-doc-paylink/paylink' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx-doc-paylink',
                'payment_url' => 'https://my-coolpay.com/pay/doc',
            ], 201),
        ]);

        $response = $this->postJson('/v1/payments/paylink', [
            'channel' => 'mtn_momo',
            'amount' => 100,
            'currency' => 'XAF',
            'reason' => 'Order #1234 — Premium plan',
            'customer_phone_number' => '+2376XXXXXXXX',
            'customer_name' => 'Marie Dupont',
            'customer_email' => 'marie.dupont@example.com',
            'customer_lang' => 'fr',
            'return_url' => 'https://example.com/payments/return',
            'wallet_recipient_external_ref' => 'wallet-customer-42',
            'callback_url' => 'https://example.com/webhooks/core',
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(201);
    }

    #[Test]
    public function post_payments_payin_with_the_full_documented_example(): void
    {
        [$token, $projectId] = $this->authenticatedProject();
        $this->registerMyCoolPayApplication($projectId, 'pub-doc-payin');
        $this->creditWalletExternalRef($projectId, 'wallet-customer-42');

        Http::fake([
            'my-coolpay.com/api/pub-doc-payin/payin' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx-doc-payin',
                'action' => 'PENDING',
            ], 201),
        ]);

        $response = $this->postJson('/v1/payments/payin', [
            'channel' => 'mtn_momo',
            'amount' => 100,
            'currency' => 'XAF',
            'reason' => 'Order #1234 — Premium plan',
            'customer_phone_number' => '+2376XXXXXXXX',
            'customer_name' => 'Marie Dupont',
            'customer_email' => 'marie.dupont@example.com',
            'customer_lang' => 'fr',
            'wallet_recipient_external_ref' => 'wallet-customer-42',
            'callback_url' => 'https://example.com/webhooks/core',
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(201);
    }

    #[Test]
    public function get_payment_status_after_starting_one(): void
    {
        [$token, $projectId] = $this->authenticatedProject();
        $this->registerMyCoolPayApplication($projectId, 'pub-doc-get');

        Http::fake([
            'my-coolpay.com/api/pub-doc-get/payin' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx-doc-get',
                'action' => 'PENDING',
            ], 201),
        ]);

        $start = $this->postJson('/v1/payments/payin', [
            'channel' => 'mtn_momo',
            'amount' => 100,
            'currency' => 'XAF',
            'reason' => 'Order #1234 — Premium plan',
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
        $paymentId = $start->json('id');
        self::assertIsString($paymentId);

        $this->getJson("/v1/payments/{$paymentId}", ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('id', $paymentId);
    }

    #[Test]
    public function post_webhook_endpoints_get_list_and_delete_with_the_documented_example(): void
    {
        [$token] = $this->authenticatedProject();

        $register = $this->postJson('/v1/webhook-endpoints', [
            'url' => 'https://example.com/webhooks/core',
            'events' => ['payment.status_changed'],
        ], ['Authorization' => "Bearer {$token}"]);
        $register->assertStatus(201);
        $endpointId = $register->json('id');
        self::assertIsString($endpointId);

        $this->getJson('/v1/webhook-endpoints', ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        $this->deleteJson("/v1/webhook-endpoints/{$endpointId}", [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(204);
    }

    #[Test]
    public function get_wallet_balance_with_a_credited_wallet(): void
    {
        [$token, $projectId] = $this->authenticatedProject();
        $walletId = $this->creditWalletExternalRef($projectId, 'wallet-customer-42');

        $this->getJson("/v1/wallets/{$walletId}/balance", ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('wallet_id', $walletId);
    }

    #[Test]
    public function post_notifications_text_with_the_full_documented_example(): void
    {
        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);
        Http::fake(['waha.test/api/sendText' => Http::response(['id' => 'msg-1'], 201)]);

        [$token] = $this->authenticatedProject();

        $response = $this->postJson('/v1/notifications/text', [
            'channel' => 'whatsapp',
            'to_party_ref' => 'customer-42',
            'text' => 'Hello Marie, your order #1234 is confirmed.',
            'variables' => ['phone_number' => '+2376XXXXXXXX'],
            'client_reference' => 'client-msg-abc123',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'sent');
    }

    #[Test]
    public function post_notifications_image_with_the_full_documented_example(): void
    {
        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);
        Http::fake(['waha.test/api/sendImage' => Http::response(['id' => 'msg-2'], 201)]);

        [$token] = $this->authenticatedProject();

        $response = $this->postJson('/v1/notifications/image', [
            'channel' => 'whatsapp',
            'to_party_ref' => 'customer-42',
            'media_url' => 'https://upload.wikimedia.org/wikipedia/commons/4/47/PNG_transparency_demonstration_1.png',
            'caption' => 'Your order confirmation',
            'filename' => 'order-1234.png',
            'mimetype' => 'image/png',
            'variables' => ['phone_number' => '+2376XXXXXXXX'],
            'client_reference' => 'client-msg-abc123',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'sent');
    }

    #[Test]
    public function post_notifications_file_with_the_full_documented_example(): void
    {
        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);
        Http::fake(['waha.test/api/sendFile' => Http::response(['id' => 'msg-3'], 201)]);

        [$token] = $this->authenticatedProject();

        $response = $this->postJson('/v1/notifications/file', [
            'channel' => 'whatsapp',
            'to_party_ref' => 'customer-42',
            'media_url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
            'filename' => 'invoice-1234.pdf',
            'mimetype' => 'application/pdf',
            'caption' => 'Your invoice for order #1234',
            'variables' => ['phone_number' => '+2376XXXXXXXX'],
            'client_reference' => 'client-msg-abc123',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'sent');
    }

    #[Test]
    public function get_notification_status_after_sending_one(): void
    {
        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);
        Http::fake(['waha.test/api/sendText' => Http::response(['id' => 'msg-4'], 201)]);

        [$token] = $this->authenticatedProject();

        $send = $this->postJson('/v1/notifications/text', [
            'channel' => 'whatsapp',
            'to_party_ref' => 'customer-42',
            'text' => 'Hello Marie, your order #1234 is confirmed.',
            'variables' => ['phone_number' => '+2376XXXXXXXX'],
        ], ['Authorization' => "Bearer {$token}"]);
        $notificationId = $send->json('id');
        self::assertIsString($notificationId);

        $this->getJson("/v1/notifications/{$notificationId}", ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('id', $notificationId);
    }

    #[Test]
    public function post_notification_channel_sessions_pairing_code_with_the_documented_example(): void
    {
        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);
        Http::fake([
            'waha.test/api/sessions' => Http::response(['name' => 'session-doc'], 201),
            'waha.test/api/*/auth/request-code' => Http::response(['code' => 'ABCD-1234'], 200),
        ]);

        [$token] = $this->authenticatedProject();

        $response = $this->postJson('/v1/notification-channel-sessions/whatsapp/session-doc/pairing-code', [
            'phone_number' => '+2376XXXXXXXX',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $response->assertJsonPath('code', 'ABCD-1234');
    }

    #[Test]
    public function get_notification_channel_sessions_qr(): void
    {
        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);
        Http::fake([
            'waha.test/api/sessions' => Http::response(['name' => 'session-doc'], 201),
            'waha.test/api/*/auth/qr' => Http::response('fake-qr-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        [$token] = $this->authenticatedProject();

        $this->getJson('/v1/notification-channel-sessions/whatsapp/session-doc/qr', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonStructure(['mime_type', 'data']);
    }

    #[Test]
    public function get_notification_channel_sessions_screenshot(): void
    {
        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);
        Http::fake([
            'waha.test/api/sessions' => Http::response(['name' => 'session-doc'], 201),
            'waha.test/api/screenshot*' => Http::response('fake-png-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        [$token] = $this->authenticatedProject();

        $this->getJson('/v1/notification-channel-sessions/whatsapp/session-doc/screenshot', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonStructure(['mime_type', 'data']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * @return array{0: string, 1: string} [bearer token, project id]
     */
    private function authenticatedProject(): array
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
            new IssueApiKeyCommand($project->id, Environment::Sandbox, ApiKeyScopes::ALL),
            actor: 'service:test',
            correlationId: null,
        );

        return [$issued->plaintextToken, $project->id];
    }

    private function registerMyCoolPayApplication(string $projectId, string $publicKey): void
    {
        app(ProviderApplicationRepository::class)->save(new ProviderApplication(
            projectId: $projectId,
            providerCode: 'my-coolpay',
            publicKey: $publicKey,
            privateKey: 'test-private-key',
        ));
    }

    private function creditWalletExternalRef(string $projectId, string $externalRef): string
    {
        $view = app(CreditWallet::class)->credit(new CreditWalletCommand(
            $projectId,
            (string) Str::uuid(),
            1,
            'XAF',
            $externalRef,
            (string) Str::uuid(),
        ));

        return $view->walletId;
    }
}
