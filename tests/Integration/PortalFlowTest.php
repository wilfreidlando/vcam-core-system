<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\InvoicePaymentView;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\PayInvoice;
use Core\Billing\Contracts\PayInvoiceCommand;
use Core\Billing\Contracts\PayInvoiceResult;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Payment\Contracts\PaymentChannel;
use Core\Payment\Contracts\PaymentProviderRouter;
use Core\Support\Integration\Provider\ProviderCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Stringable;
use Tests\TestCase;

/**
 * ADR-0075 / ADR-0097, end to end: a project's server opens a portal for
 * one of its customers with a single call, the browser exchanges the
 * one-time link for a cookie, and the portal shows only what belongs to
 * that customer.
 *
 * App-level on purpose (G3c): it orchestrates `identity` and `billing`
 * fixtures together with the portal.
 */
final class PortalFlowTest extends TestCase
{
    use RefreshDatabase;

    private const RETURN_URL = 'https://app.mon-saas.cm/compte';

    #[Test]
    public function one_call_declares_the_customer_subscribes_it_and_returns_a_one_time_link(): void
    {
        [$token, $projectId] = $this->project(['portal:write', 'customers:write', 'subscriptions:write']);
        $this->plan($projectId);
        $this->allowReturnUrl($token);

        $payload = [
            'customer_ref' => 'AE0001',
            'customer' => ['display_name' => 'Auto-école Soleil', 'email' => 'direction@soleil.cm'],
            'plan_code' => 'pro',
            'trial_days' => 14,
            'return_url' => self::RETURN_URL.'/billing',
        ];

        $first = $this->postJson('/v1/portal/sessions', $payload, $this->auth($token))
            ->assertStatus(201)
            ->assertJsonPath('customer_created', true)
            ->assertJsonPath('subscription_created', true);

        self::assertStringContainsString('/portal/enter/', $this->jsonString($first, 'url'));
        self::assertSame(1, DB::table('subscriptions')->count());

        // Safe on every login: the same call again subscribes nobody twice.
        $this->postJson('/v1/portal/sessions', $payload, $this->auth($token))
            ->assertStatus(201)
            ->assertJsonPath('customer_created', false)
            ->assertJsonPath('subscription_created', false);

        self::assertSame(1, DB::table('subscriptions')->count());

        // Only the hash of the link is stored.
        $path = (string) parse_url($this->jsonString($first, 'url'), PHP_URL_PATH);
        $secret = substr($path, strlen('/portal/enter/'));
        self::assertSame(0, DB::table('portal_sessions')->where('token_hash', $secret)->count());
    }

    #[Test]
    public function a_partial_call_does_not_erase_what_an_earlier_one_declared(): void
    {
        [$token, $projectId] = $this->project(['portal:write', 'customers:write']);

        $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001', 'customer' => ['display_name' => 'Soleil', 'email' => 'a@soleil.cm']], $this->auth($token))->assertStatus(201);
        $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001', 'customer' => ['display_name' => 'Soleil 2']], $this->auth($token))->assertStatus(201);

        $view = app(\Core\Billing\Contracts\GetCustomer::class)->get(
            new \Core\Billing\Contracts\GetCustomerCommand($projectId, 'AE0001'),
            \Core\Support\Access\AccessScope::forProject($projectId),
        );

        self::assertNotNull($view);
        self::assertSame('Soleil 2', $view->displayName);
        self::assertSame('a@soleil.cm', $view->email);
    }

    #[Test]
    public function a_key_that_may_only_open_portals_cannot_write_customers_or_contracts_by_the_side_door(): void
    {
        [$token] = $this->project(['portal:write']);

        $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001', 'customer' => ['display_name' => 'X']], $this->auth($token))->assertStatus(403);
        $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001', 'plan_code' => 'pro'], $this->auth($token))->assertStatus(403);

        [$other] = $this->project(['customers:read']);
        $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001'], $this->auth($other))->assertStatus(403);
    }

    #[Test]
    public function an_unknown_customer_has_no_portal(): void
    {
        [$token] = $this->project(['portal:write']);

        $this->postJson('/v1/portal/sessions', ['customer_ref' => 'ghost'], $this->auth($token))->assertStatus(404);
    }

    #[Test]
    public function a_return_url_outside_the_projects_list_is_refused(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->customer($projectId, 'AE0001');
        $this->allowReturnUrl($token);

        foreach ([
            'https://app.mon-saas.cm.evil.io/compte',
            'https://evil.io/?next=https://app.mon-saas.cm/compte',
            'https://app.mon-saas.cm/compteevil',
            'http://app.mon-saas.cm/compte',
            'https://user:pass@app.mon-saas.cm/compte',
            'javascript:alert(1)',
            'https://app.mon-saas.cm/compte/%2e%2e/admin',
            'https://app.mon-saas.cm/compte/..%2fadmin',
        ] as $url) {
            $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001', 'return_url' => $url], $this->auth($token))
                ->assertStatus(422);
        }

        $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001', 'return_url' => self::RETURN_URL.'/factures'], $this->auth($token))
            ->assertStatus(201);
    }

    #[Test]
    public function the_link_works_once_then_the_cookie_opens_the_home_with_strict_headers(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->customer($projectId, 'AE0001', 'Auto-école Soleil');
        $this->allowReturnUrl($token);

        $url = $this->jsonString($this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001', 'return_url' => self::RETURN_URL], $this->auth($token)), 'url');
        $path = (string) parse_url($url, PHP_URL_PATH);

        $enter = $this->get($path)->assertRedirect('/portal');
        $cookie = collect($enter->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'portal');

        self::assertNotNull($cookie);
        self::assertNotNull($cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertNotSame(substr($path, strlen('/portal/enter/')), $cookie->getValue(), 'The cookie is a new secret, never the link.');

        // The link is spent.
        $this->get($path)->assertStatus(410);

        $home = $this->withUnencryptedCookie('portal', $cookie->getValue())->get('/portal');
        $home->assertOk()->assertSee('Auto-école Soleil');
        $home->assertSee('Retour à');

        $csp = (string) $home->headers->get('Content-Security-Policy');
        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('script-src', $csp);
        $home->assertHeader('X-Frame-Options', 'DENY');
        $home->assertHeader('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) $home->headers->get('Cache-Control'));
        self::assertStringNotContainsString('<script', $this->html($home));
    }

    #[Test]
    public function an_expired_link_and_a_garbage_link_both_fail(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->customer($projectId, 'AE0001');

        $url = $this->jsonString($this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001'], $this->auth($token)), 'url');
        DB::table('portal_sessions')->update(['token_expires_at' => now()->subMinute()]);

        $this->get((string) parse_url($url, PHP_URL_PATH))->assertStatus(410);
        $this->get('/portal/enter/'.str_repeat('a', 43))->assertStatus(410);
    }

    #[Test]
    public function without_a_session_nothing_is_shown(): void
    {
        $this->get('/portal')->assertStatus(401);
        $this->get('/portal/invoices')->assertStatus(401);
        $this->withUnencryptedCookie('portal', 'forged')->get('/portal')->assertStatus(401);
    }

    #[Test]
    public function an_idle_session_closes_after_thirty_minutes(): void
    {
        $cookie = $this->login();

        $this->withUnencryptedCookie('portal', $cookie)->get('/portal')->assertOk();

        DB::table('portal_sessions')->update(['last_seen_at' => now()->subMinutes(31)]);

        $this->withUnencryptedCookie('portal', $cookie)->get('/portal')->assertStatus(401);
    }

    #[Test]
    public function a_state_changing_request_needs_the_anti_forgery_token_and_logout_ends_the_session(): void
    {
        $cookie = $this->login();

        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/logout')->assertStatus(419);
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal')->assertOk();

        $csrf = $this->csrfOf($cookie);
        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/logout', ['_csrf' => $csrf])->assertOk();

        $this->withUnencryptedCookie('portal', $cookie)->get('/portal')->assertStatus(401);
    }

    #[Test]
    public function a_customer_sees_its_own_invoice_and_a_neighbours_is_not_found(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->customer($projectId, 'AE0001', 'Soleil');
        $this->customer($projectId, 'AE0002', 'Lune');
        $mine = $this->invoice($projectId, 'AE0001', 'Abonnement Soleil');
        $theirs = $this->invoice($projectId, 'AE0002', 'Abonnement Lune');

        $cookie = $this->loginAs($token, 'AE0001');

        $this->withUnencryptedCookie('portal', $cookie)->get('/portal/invoices/'.$mine)
            ->assertOk()->assertSee('Abonnement Soleil')->assertSee('50');

        // Not "forbidden": the neighbour's invoice simply does not exist for this customer (G15).
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal/invoices/'.$theirs)->assertStatus(404);

        $list = $this->withUnencryptedCookie('portal', $cookie)->get('/portal/invoices')->assertOk();
        $list->assertDontSee('Lune');
    }

    #[Test]
    public function paying_with_a_channel_that_is_not_offered_is_refused_and_nothing_starts(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->customer($projectId, 'AE0001');
        $invoice = $this->invoice($projectId, 'AE0001', 'Abonnement');
        $cookie = $this->loginAs($token, 'AE0001');
        $csrf = $this->csrfOf($cookie);

        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/invoices/'.$invoice.'/pay', [
            '_csrf' => $csrf, 'channel' => 'bitcoin', 'phone' => '+237657467941', 'nonce' => bin2hex(random_bytes(16)),
        ])->assertStatus(422)->assertSee('pas proposé');

        self::assertSame(0, DB::table('invoice_payments')->count());
    }

    #[Test]
    public function a_double_click_on_pay_is_one_payment_and_a_new_form_is_a_new_one(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->customer($projectId, 'AE0001');
        $invoice = $this->invoice($projectId, 'AE0001', 'Abonnement');

        // A platform that can route mobile money, and a payment endpoint we can watch.
        $this->app->instance(PaymentProviderRouter::class, new class implements PaymentProviderRouter
        {
            public function resolveProvider(PaymentChannel $channel, string $projectId): ProviderCode
            {
                return ProviderCode::fromString('test-provider');
            }
        });
        $spy = new class implements PayInvoice
        {
            /** @var list<PayInvoiceCommand> */
            public array $commands = [];

            public function pay(PayInvoiceCommand $command): PayInvoiceResult
            {
                $this->commands[] = $command;

                return new PayInvoiceResult(
                    new InvoicePaymentView('p1', $command->invoiceId, $command->projectId, 'pay-1', 'pending', 50_000, 'XAF'),
                    requiresAction: true,
                    actionHint: 'Validez sur votre téléphone.',
                );
            }
        };
        $this->app->instance(PayInvoice::class, $spy);

        $cookie = $this->loginAs($token, 'AE0001');
        $csrf = $this->csrfOf($cookie);

        // The invoice page offers the channels the platform routes — none configured by the project.
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal/invoices/'.$invoice)
            ->assertOk()->assertSee('MTN Mobile Money')->assertSee('Orange Money');

        $form = ['_csrf' => $csrf, 'channel' => 'mtn_momo', 'phone' => '+237 657 46 79 41', 'nonce' => str_repeat('ab', 16)];

        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/invoices/'.$invoice.'/pay', $form)
            ->assertOk()->assertSee('Confirmez sur votre téléphone');
        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/invoices/'.$invoice.'/pay', $form)->assertOk();
        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/invoices/'.$invoice.'/pay', ['nonce' => str_repeat('cd', 16)] + $form)->assertOk();

        self::assertCount(3, $spy->commands);
        self::assertSame($spy->commands[0]->idempotencyKey, $spy->commands[1]->idempotencyKey, 'Same form → same key → the payment module deduplicates.');
        self::assertNotSame($spy->commands[0]->idempotencyKey, $spy->commands[2]->idempotencyKey);
        self::assertSame('+237657467941', $spy->commands[0]->customerPhoneNumber);
        self::assertStringEndsWith('/portal/invoices/'.$invoice, (string) $spy->commands[0]->returnUrl);
    }

    #[Test]
    public function a_customer_changes_plan_only_among_public_plans_and_only_when_allowed(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->customer($projectId, 'AE0001');
        $this->plan($projectId, 'pro', 25_000);
        $this->plan($projectId, 'premium', 60_000);
        $this->plan($projectId, 'secret', 5_000, 'private');
        $subscription = app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId, customerRef: 'AE0001', planCode: 'pro', trialDays: 14, startAt: null,
            idempotencyKey: 'sub', actor: 'test', correlationId: null,
        ));

        $cookie = $this->loginAs($token, 'AE0001');
        $csrf = $this->csrfOf($cookie);

        // The catalogue of public plans is always visible; the button to move is not.
        $catalogue = $this->withUnencryptedCookie('portal', $cookie)->get('/portal/plans')->assertOk();
        $catalogue->assertSee('Premium')->assertSee('Votre offre');
        $catalogue->assertDontSee('Choisir cette offre')->assertDontSee('secret');
        // …and the action is refused until the project allows it.
        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/subscriptions/'.$subscription->id.'/change-plan', ['_csrf' => $csrf, 'plan' => 'premium'])->assertStatus(422);

        $this->putJson('/v1/portal/settings', ['display_name' => 'Mon SaaS', 'allow_plan_change' => true], $this->auth($token))->assertOk();
        $cookie = $this->loginAs($token, 'AE0001');
        $csrf = $this->csrfOf($cookie);

        $page = $this->withUnencryptedCookie('portal', $cookie)->get('/portal/plans')->assertOk();
        $page->assertSee('Choisir cette offre')->assertSee('Offre actuelle')->assertSee('60');
        $page->assertDontSee('secret');

        // A private plan is not reachable by guessing its code.
        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/subscriptions/'.$subscription->id.'/change-plan', ['_csrf' => $csrf, 'plan' => 'secret'])->assertStatus(422);
        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/subscriptions/'.$subscription->id.'/change-plan', ['_csrf' => $csrf, 'plan' => 'pro'])->assertStatus(422);

        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/subscriptions/'.$subscription->id.'/change-plan', ['_csrf' => $csrf, 'plan' => 'premium'])
            ->assertRedirect('/portal/subscriptions');
        self::assertNotNull(DB::table('subscriptions')->where('id', $subscription->id)->value('pending_plan_version_id'));
    }

    #[Test]
    public function the_dashboard_the_payment_history_and_the_profile_speak_for_this_customer_only(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->customer($projectId, 'AE0001', 'Auto-école Soleil');
        $this->customer($projectId, 'AE0002', 'Auto-école Lune');
        $this->invoice($projectId, 'AE0001', 'Abonnement Soleil');
        $this->invoice($projectId, 'AE0002', 'Abonnement Lune');

        $cookie = $this->loginAs($token, 'AE0001');
        $get = fn (string $path) => $this->withUnencryptedCookie('portal', $cookie)->get($path);

        $get('/portal')->assertOk()->assertSee('À régler')->assertSee('Votre abonnement')->assertSee('50')->assertDontSee('Lune');
        $get('/portal/payments')->assertOk()->assertSee('Aucun paiement');
        $get('/portal/profile')->assertOk()->assertSee('Auto-école Soleil')->assertSee('AE0001')->assertDontSee('Lune');
        $get('/portal/usage')->assertOk();
    }

    #[Test]
    public function the_one_time_link_never_reaches_the_logs(): void
    {
        $logs = new class extends \Psr\Log\AbstractLogger
        {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = $message.json_encode($context);
            }
        };
        $this->app->instance(\Psr\Log\LoggerInterface::class, $logs);

        [$token, $projectId] = $this->project(['portal:write']);
        $this->customer($projectId, 'AE0001');

        $link = $this->jsonString($this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001'], $this->auth($token))->assertStatus(201), 'url');
        $secret = substr((string) parse_url($link, PHP_URL_PATH), strlen('/portal/enter/'));
        $this->get('/portal/enter/'.$secret);

        self::assertNotEmpty($logs->lines);
        self::assertStringNotContainsString($secret, implode("\n", $logs->lines), 'A one-time link is a credential: it must never be logged.');
    }

    #[Test]
    public function an_erased_customer_loses_its_open_session_at_once(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->customer($projectId, 'AE0001');
        $cookie = $this->loginAs($token, 'AE0001');

        $this->withUnencryptedCookie('portal', $cookie)->get('/portal')->assertOk();

        app(\Core\Billing\Contracts\EraseCustomer::class)->erase(new \Core\Billing\Contracts\EraseCustomerCommand($projectId, 'AE0001', 'test', null));

        $this->withUnencryptedCookie('portal', $cookie)->get('/portal')->assertStatus(401);
    }

    #[Test]
    public function a_customer_subscribes_renews_and_keeps_its_contract_alone_when_the_project_allows_it(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->customer($projectId, 'AE0001', 'Soleil');
        $this->plan($projectId, 'pro', 25_000);

        $cookie = $this->loginAs($token, 'AE0001');
        $csrf = $this->csrfOf($cookie);
        $nonce = str_repeat('ab', 16);
        $act = fn (string $path, array $body = []) => $this->withUnencryptedCookie('portal', $cookie)->post($path, ['_csrf' => $csrf] + $body);

        // Nothing is self-service until the project says so.
        $act('/portal/plans/pro/subscribe', ['nonce' => $nonce])->assertStatus(422);

        $this->putJson('/v1/portal/settings', ['display_name' => 'Mon SaaS', 'allow_plan_change' => true, 'allow_self_cancel' => true], $this->auth($token))->assertOk();
        $cookie = $this->loginAs($token, 'AE0001');
        $csrf = $this->csrfOf($cookie);

        // A customer with no contract sees « Souscrire » and takes one; a replay is not a second contract.
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal/plans')->assertOk()->assertSee('Souscrire');
        $act('/portal/plans/pro/subscribe', ['nonce' => $nonce])->assertRedirect('/portal/invoices');
        // A double click is a success, not an error: the customer got what it asked for.
        $act('/portal/plans/pro/subscribe', ['nonce' => $nonce])->assertRedirect('/portal/invoices');
        self::assertSame(1, DB::table('subscriptions')->count());
        $act('/portal/plans/ghost/subscribe', ['nonce' => str_repeat('cd', 16)])->assertStatus(422);

        // Bring the contract to life with a complimentary period.
        $subscriptionId = $this->scalar(DB::table('subscriptions')->value('id'));
        app(\Core\Billing\Contracts\GrantComplimentaryPeriod::class)->grant(new \Core\Billing\Contracts\GrantComplimentaryPeriodCommand(
            projectId: $projectId, subscriptionId: $subscriptionId, days: 30, reason: 'test', actor: 'test', correlationId: null,
        ));

        // « Do not renew » can be taken back; an ended contract is renewed as a new one.
        $act('/portal/subscriptions/'.$subscriptionId.'/cancel')->assertRedirect('/portal/subscriptions');
        self::assertTrue((bool) DB::table('subscriptions')->where('id', $subscriptionId)->value('cancel_at_period_end'));
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal/subscriptions')->assertSee('Reprendre le renouvellement');

        $act('/portal/subscriptions/'.$subscriptionId.'/keep')->assertRedirect('/portal/subscriptions');
        self::assertFalse((bool) DB::table('subscriptions')->where('id', $subscriptionId)->value('cancel_at_period_end'));
        self::assertSame(1, DB::table('event_outbox')->where('type', 'subscription.resumed')->where('payload->cancel_at_period_end', false)->count());

        app(\Core\Billing\Contracts\CancelSubscription::class)->cancel(new \Core\Billing\Contracts\CancelSubscriptionCommand(
            projectId: $projectId, subscriptionId: $subscriptionId, atPeriodEnd: false, reason: 'test', actor: 'test',
        ));
        $page = $this->withUnencryptedCookie('portal', $cookie)->get('/portal/subscriptions')->assertSee('Renouveler mon abonnement');
        preg_match('/name="nonce" value="([a-f0-9]+)"/', $this->html($page), $found);

        $act('/portal/subscriptions/'.$subscriptionId.'/renew', ['nonce' => $found[1] ?? ''])->assertRedirect('/portal/invoices');
        self::assertSame(2, DB::table('subscriptions')->count());

        // Another customer's contract cannot be renewed, kept or cancelled from this session.
        $this->customer($projectId, 'AE0002', 'Lune');
        $foreign = app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId, customerRef: 'AE0002', planCode: 'pro', trialDays: 14, startAt: null,
            idempotencyKey: 'foreign', actor: 'test', correlationId: null,
        ));
        $act('/portal/subscriptions/'.$foreign->id.'/keep')->assertStatus(422);
        $act('/portal/subscriptions/'.$foreign->id.'/cancel')->assertStatus(422);
    }

    #[Test]
    public function the_settings_are_validated_and_drive_the_portal_look(): void
    {
        [$token] = $this->project(['portal:write']);

        $this->getJson('/v1/portal/settings', $this->auth($token))->assertOk()->assertJsonPath('primary_color', '#2563eb');

        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil', 'primary_color' => 'red'], $this->auth($token))->assertStatus(422);
        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil', 'logo_url' => 'http://x.cm/l.png'], $this->auth($token))->assertStatus(422);
        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil', 'allowed_return_urls' => ['https://ok.cm', 'ftp://bad']], $this->auth($token))->assertStatus(422);

        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil', 'primary_color' => '#0F766E', 'allowed_return_urls' => ['https://ok.cm']], $this->auth($token))
            ->assertOk()->assertJsonPath('primary_color', '#0f766e');

        $cookie = $this->login(token: $token);
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal/theme.css')->assertOk()->assertSee('--brand:#0f766e', false);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param list<string> $scopes
     * @return array{0: string, 1: string}
     */
    private function project(array $scopes): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project;
        $issued = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project->id, $scopes), actor: 'service:test', correlationId: null);

        return [$issued->plaintextToken, $project->id];
    }

    /**
     * @return array<string, string>
     */
    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function allowReturnUrl(string $token): void
    {
        $this->putJson('/v1/portal/settings', ['display_name' => 'Mon SaaS', 'allowed_return_urls' => ['https://app.mon-saas.cm/compte']], $this->auth($token))->assertOk();
    }

    private function customer(string $projectId, string $ref, string $name = 'Client'): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: $ref, displayName: $name, email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
    }

    private function plan(string $projectId, string $code = 'pro', int $price = 25_000, string $visibility = 'public'): void
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: $projectId, code: $code, name: ucfirst($code), description: null, visibility: $visibility,
            sortOrder: 0, actor: 'test', correlationId: null,
        ));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId, planCode: $code, priceMinor: $price, currency: 'XAF', intervalUnit: 'month',
            intervalCount: 1, trialDays: 0, graceDays: 7, graceAccess: true,
            features: ['students' => ['type' => 'limit', 'meter' => 'students', 'limit' => 100, 'reset' => 'never', 'overage' => 'block']],
            actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: $projectId, planCode: $code, version: $version->version, actor: 'test', correlationId: null,
        ));
    }

    private function invoice(string $projectId, string $ref, string $description): string
    {
        $draft = app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: $projectId, customerRef: $ref,
            lines: [new InvoiceLineInput(description: $description, quantity: 1, unitAmountMinor: 50_000)],
            actor: 'test', correlationId: null,
        ));

        return app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: $projectId, invoiceId: $draft->id, actor: 'test', correlationId: null,
        ))->id;
    }

    private function login(?string $token = null): string
    {
        if ($token === null) {
            [$token, $projectId] = $this->project(['portal:write']);
            $this->customer($projectId, 'AE0001', 'Soleil');
        } else {
            $projectId = $this->firstProjectId();
            $this->customer($projectId, 'AE0001', 'Soleil');
        }

        return $this->loginAs($token, 'AE0001');
    }

    private function loginAs(string $token, string $ref): string
    {
        $url = $this->jsonString($this->postJson('/v1/portal/sessions', ['customer_ref' => $ref], $this->auth($token))->assertStatus(201), 'url');
        $response = $this->get((string) parse_url($url, PHP_URL_PATH));
        $cookie = collect($response->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'portal');

        return $cookie?->getValue() ?? '';
    }

    /**
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function jsonString($response, string $key): string
    {
        $value = $response->json($key);
        self::assertIsString($value);

        return $value;
    }

    private function firstProjectId(): string
    {
        $id = DB::table('api_keys')->value('project_id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function html($response): string
    {
        // Larastan models TestResponse's forwarded getContent() as static.
        $content = $response->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        return is_string($content) ? $content : '';
    }

    private function csrfOf(string $cookie): string
    {
        $html = $this->html($this->withUnencryptedCookie('portal', $cookie)->get('/portal'));
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    private function scalar(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
