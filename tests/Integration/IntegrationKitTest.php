<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Billing\ApplyBillingEvent;
use App\Core\Billing\CoreBilling;
use App\Core\Billing\QuotaExceeded;
use App\Core\CoreClient;
use App\Core\CoreException;
use App\Core\Notifications\CoreNotifications;
use App\Core\Payments\CorePayments;
use App\Core\Wallets\CoreWallets;
use App\Core\Webhooks\CoreEventReceived;
use App\Core\Webhooks\CoreWebhookController;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Identity\Contracts\ApiKeyScopes;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Notification\Domain\Model\ChannelSession;
use Core\Notification\Domain\Port\ChannelSessionRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Laravel kit (docs/integration/kit-laravel) executed against THIS Core.
 *
 * Nothing is mocked between the kit and the Core: the kit's HTTP client is
 * wired, through `Http::fake()`, to the application's own kernel — so what the
 * documentation tells a SaaS to copy is proven to work, route by route, and a
 * change of the public API that breaks the kit breaks this test.
 *
 * Covers the three profiles of INSTALL.md: payments + wallets (e-tourisme
 * style), notifications, and SaaS commerce.
 */
final class IntegrationKitTest extends TestCase
{
    use RefreshDatabase;

    // The kit is autoloaded as `App\Core\` (composer.json, autoload-dev): the very files a SaaS copies.
    private const KIT = __DIR__.'/../../docs/integration/kit-laravel';

    private string $token;

    private string $projectId;

    #[Test]
    public function a_marketplace_collects_a_payment_splits_it_and_pays_a_partner_out(): void
    {
        $payments = app(CorePayments::class);
        $wallets = app(CoreWallets::class);

        // The payer is sent to pay; the payment names the escrow wallet to credit on success.
        $order = 'order-'.Str::uuid();
        $started = $payments->start([
            'channel' => 'mtn_momo', 'amount' => 50_000, 'currency' => 'XAF', 'reason' => 'Réservation ETR-7K3Q9',
            'wallet_recipient_external_ref' => 'escrow',
        ], $order, 'payin');
        self::assertFalse($started['is_final']);

        // The same key again is the same payment, not a second charge.
        self::assertSame($started['id'], $payments->start([
            'channel' => 'mtn_momo', 'amount' => 50_000, 'currency' => 'XAF', 'reason' => 'Réservation ETR-7K3Q9',
            'wallet_recipient_external_ref' => 'escrow',
        ], $order, 'payin')['id']);

        $payments->simulate($started['id'], 'succeeded', 'sim-'.$order);
        $read = $payments->get($started['id']);
        self::assertSame('succeeded', $read['status']);
        self::assertTrue(CorePayments::isFinal($read['status']));

        // The money is in the escrow wallet; settle it between the partner and the platform.
        self::assertSame(50_000, $wallets->available('escrow', 'XAF'));
        $wallets->transfer('settle-'.$order, 'XAF', [
            ['from' => 'escrow', 'to' => 'partner:7', 'amount' => 45_000, 'to_party_type' => 'merchant'],
            ['from' => 'escrow', 'to' => 'platform', 'amount' => 5_000],
        ], 'Règlement ETR-7K3Q9', ['order' => 'ETR-7K3Q9']);
        self::assertSame(45_000, $wallets->available('partner:7', 'XAF'));
        self::assertSame(0, $wallets->available('escrow', 'XAF'));

        // The partner is paid out by the SaaS itself; the Core keeps the proof.
        $wallets->withdraw('payout-'.$order, 'partner:7', 20_000, 'XAF', 'Retrait MTN', 'MP241014.1532.C12345');
        $walletId = $wallets->ofParty('partner:7')[0]['wallet_id'];
        self::assertSame(25_000, $wallets->balance($walletId)['available_minor']);
        $history = $wallets->transactions($walletId);
        self::assertSame(2, $history['meta']['total']);
        self::assertSame('withdrawal', $history['data'][0]['type']);

        // A short wallet is refused with a typed error, and nothing moves.
        try {
            $wallets->withdraw('payout-too-much', 'partner:7', 999_999, 'XAF');
            self::fail('The Core should have refused an overdraft.');
        } catch (CoreException $e) {
            self::assertTrue($e->is('insufficient-funds'));
            self::assertFalse($e->isTransient());
            self::assertSame(409, $e->status);
        }
        self::assertSame(25_000, $wallets->available('partner:7', 'XAF'));
    }

    #[Test]
    public function a_whatsapp_message_leaves_through_the_kit_once_per_client_reference(): void
    {
        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test', 'core.notifications.whatsapp_session' => 'ma-boutique']);
        Http::fake(['waha.test/api/sendText' => Http::response(['id' => 'msg-1'], 201)]);
        app(ChannelSessionRepository::class)->save(new ChannelSession((string) Str::uuid(), $this->projectId, 'whatsapp', 'ma-boutique', new DateTimeImmutable));

        $notifications = app(CoreNotifications::class);
        $first = $notifications->text('customer-42', '+237657467941', 'Votre réservation est confirmée', 'booking-1-confirmed');
        $again = $notifications->text('customer-42', '+237657467941', 'Votre réservation est confirmée', 'booking-1-confirmed');

        self::assertSame($first['id'], $again['id'], 'The same client reference is one message.');
        self::assertSame($first['id'], $notifications->status($first['id'])['id']);
    }

    #[Test]
    public function a_saas_provisions_a_tenant_gates_its_features_and_meets_its_quota_through_the_kit(): void
    {
        $this->publishPlan();
        // The one-time setup of INSTALL.md: where the portal may send the customer back to.
        app(CoreClient::class)->put('portal/settings', ['display_name' => 'Mon SaaS', 'allowed_return_urls' => ['https://mon-saas.test/billing']]);
        $billing = app(CoreBilling::class);

        $billing->provision('tenant-1', ['name' => 'Auto-École Soleil', 'email' => null, 'phone' => null], 'pro', 14);
        $billing->provision('tenant-1', ['name' => 'Auto-École Soleil'], 'pro', 14);   // twice: safe

        self::assertTrue($billing->canUse('tenant-1'));
        self::assertFalse($billing->canUse('tenant-unknown'), 'A tenant the Core does not know has no rights.');

        $billing->consume('tenant-1', 'students', 'student-1');
        $billing->consume('tenant-1', 'students', 'student-1');       // replayed: counted once
        $billing->consume('tenant-1', 'students', 'student-2');

        try {
            $billing->consume('tenant-1', 'students', 'student-3');
            self::fail('The plan allows two students.');
        } catch (QuotaExceeded $e) {
            self::assertSame(2, $e->used);
            self::assertSame(2, $e->limit);
        }

        self::assertStringContainsString('/portal/enter/', $billing->portalUrl('tenant-1'));
    }

    #[Test]
    public function the_webhook_endpoint_verifies_deduplicates_and_hands_the_event_to_your_listeners(): void
    {
        config(['core.webhook_secret' => 'whsec_test']);
        Route::post('/webhooks/core', CoreWebhookController::class);
        $seen = [];
        Event::listen(CoreEventReceived::class, function (CoreEventReceived $event) use (&$seen): void {
            $status = $event->data['status'] ?? '';
            $seen[] = $event->type.':'.(is_string($status) ? $status : '');
        });

        $body = (string) json_encode(['id' => 'evt-1', 'type' => 'payment.status_changed', 'data' => ['payment_id' => 'p1', 'status' => 'succeeded', 'is_final' => true]]);

        $this->deliver($body, 'wrong')->assertStatus(401);
        $this->deliver($body, 'whsec_test', time() - 3_600)->assertStatus(401);   // outside the tolerance
        $this->deliver($body, 'whsec_test')->assertStatus(204);
        $this->deliver($body, 'whsec_test')->assertStatus(204);                   // redelivery: handled once
        self::assertSame(['payment.status_changed:succeeded'], $seen);

        // During a rotation the old secret still signs `X-Signature-Previous`.
        $other = (string) json_encode(['id' => 'evt-2', 'type' => 'wallet.credited', 'data' => ['wallet_id' => 'w1']]);
        $this->deliver($other, 'garbage', previousSecret: 'whsec_test')->assertStatus(204);
        self::assertCount(2, $seen);
    }

    #[Test]
    public function the_billing_listener_clears_the_rights_cache_only_for_commerce_events(): void
    {
        Cache::put('core_billing:entitlements:tenant-1', ['active' => true], 60);
        $listener = app(ApplyBillingEvent::class);

        $listener(new CoreEventReceived('evt-3', 'wallet.credited', ['customer_ref' => 'tenant-1']));
        self::assertTrue(Cache::has('core_billing:entitlements:tenant-1'), 'A wallet event says nothing about the contract.');

        $listener(new CoreEventReceived('evt-4', 'subscription.activated', ['customer_ref' => 'tenant-1']));
        self::assertFalse(Cache::has('core_billing:entitlements:tenant-1'), 'A subscription event makes the next read fresh.');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Mon SaaS'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project;
        $this->projectId = $project->id;
        $this->token = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project->id, ApiKeyScopes::ALL), actor: 'service:test', correlationId: null)->plaintextToken;

        $kitConfig = require self::KIT.'/config/core.php';
        config(['core' => $kitConfig, 'core.base_url' => 'https://core.test', 'core.api_key' => $this->token, 'core.timeout' => 5, 'core.billing.return_url' => 'https://mon-saas.test/billing']);
        config(['payment.simulator.enabled' => true]);

        $this->app->singleton(CoreClient::class);

        // The kit's calls enter this very application.
        Http::fake(['core.test/*' => function (Request $request) {
            $headers = [];
            /** @var array<string, list<string>> $incoming */
            $incoming = $request->headers();
            foreach ($incoming as $name => $values) {
                $headers['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $values[0] ?? '';
            }
            $url = parse_url($request->url());
            $uri = ($url['path'] ?? '/').(isset($url['query']) ? '?'.$url['query'] : '');
            $response = $this->call($request->method(), $uri, [], [], [], $headers + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $request->body());

            $raw = $response->baseResponse;

            return Http::response((string) $raw->getContent(), $raw->getStatusCode(), ['Content-Type' => (string) $raw->headers->get('Content-Type'), 'Retry-After' => (string) $raw->headers->get('Retry-After')]);
        }]);
    }

    /**
     * A delivery signed the way the Core signs it. `$previousSecret` plays a
     * rotation: the current signature is garbage, the previous one is valid.
     *
     * @return TestResponse<\Illuminate\Http\Response>
     */
    private function deliver(string $body, string $secret, ?int $at = null, ?string $previousSecret = null): TestResponse
    {
        $timestamp = (string) ($at ?? time());
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $previousSecret ?? $secret);

        return $this->call('POST', '/webhooks/core', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => $previousSecret === null ? $signature : 'garbage',
        ] + ($previousSecret === null ? [] : ['HTTP_X_SIGNATURE_PREVIOUS' => $signature]), $body);
    }

    private function publishPlan(): void
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(projectId: $this->projectId, code: 'pro', name: 'Pro', description: null, visibility: 'public', sortOrder: 0, actor: 'test', correlationId: null));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $this->projectId, planCode: 'pro', priceMinor: 25_000, currency: 'XAF', intervalUnit: 'month', intervalCount: 1,
            trialDays: 0, graceDays: 7, graceAccess: true,
            features: ['students' => ['type' => 'limit', 'meter' => 'students', 'limit' => 2, 'reset' => 'never', 'overage' => 'block']],
            actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(projectId: $this->projectId, planCode: 'pro', version: $version->version, actor: 'test', correlationId: null));
    }
}
