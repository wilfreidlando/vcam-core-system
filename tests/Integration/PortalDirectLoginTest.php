<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Billing\Contracts\EraseCustomer;
use Core\Billing\Contracts\EraseCustomerCommand;
use Core\Billing\Contracts\FindCustomerByContact;
use Core\Billing\Contracts\FindCustomerByContactCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Notification\Domain\Model\ChannelSession;
use Core\Notification\Domain\Port\ChannelSessionRepository;
use Core\Portal\Domain\Exception\LoginThrottleUnavailable;
use Core\Portal\Domain\Port\LoginThrottle;
use Core\Portal\Infrastructure\Security\CacheLoginThrottle;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-0075 §2 / ADR-0100 — the login by code of the portal, end to end.
 *
 * App-level on purpose (G3c): it orchestrates `identity`, `billing`,
 * `notification` and `portal` together. The clock is injected (as in
 * SaasLifecycleFlowTest) so that expiry and the resend delay are moved,
 * not slept; WhatsApp is faked at the HTTP edge, so the code is read from
 * the message that would have left.
 */
final class PortalDirectLoginTest extends TestCase
{
    use RefreshDatabase;

    private const WAHA = 'waha.test/api/sendText';

    private const PHONE = '+237657467941';

    private DirectLoginTestClock $clock;

    #[Test]
    public function a_project_that_did_not_turn_it_on_has_no_login_page(): void
    {
        [, $projectId] = $this->project(enabled: false);
        $this->customer($projectId, 'AE0001', self::PHONE);

        $this->get($this->loginUrl($projectId))->assertNotFound();
        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE])->assertNotFound();
        $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => '123456'])->assertNotFound();

        Http::assertNothingSent();
        self::assertSame(0, DB::table('portal_login_codes')->count());
    }

    #[Test]
    public function enabling_it_needs_the_whatsapp_session_the_code_leaves_from(): void
    {
        [$token] = $this->project(enabled: false);
        $auth = ['Authorization' => "Bearer {$token}"];

        $this->putJson('/v1/portal/settings', ['display_name' => 'Mon Auto-École', 'direct_login_enabled' => true], $auth)->assertStatus(422);
        $this->putJson('/v1/portal/settings', ['display_name' => 'Mon Auto-École', 'direct_login_enabled' => true, 'direct_login_session' => 'bad session!'], $auth)->assertStatus(422);

        $this->putJson('/v1/portal/settings', ['display_name' => 'Mon Auto-École', 'direct_login_enabled' => true, 'direct_login_session' => 'ecole'], $auth)
            ->assertOk()
            ->assertJsonPath('direct_login_enabled', true)
            ->assertJsonPath('direct_login_session', 'ecole');

        $this->getJson('/v1/portal/settings', $auth)->assertJsonPath('direct_login_enabled', true);

        // Off again: the page is gone.
        $this->putJson('/v1/portal/settings', ['display_name' => 'Mon Auto-École'], $auth)->assertOk()->assertJsonPath('direct_login_enabled', false);
    }

    #[Test]
    public function the_login_page_is_a_strict_script_free_page_in_the_projects_brand(): void
    {
        [, $projectId] = $this->project();

        $page = $this->get($this->loginUrl($projectId))->assertOk()->assertSee('Mon Auto-École')->assertSee('Recevoir mon code');

        $csp = (string) $page->headers->get('Content-Security-Policy');
        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringNotContainsString('script-src', $csp);
        $page->assertHeader('X-Frame-Options', 'DENY');
        self::assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        self::assertStringNotContainsString('<script', $this->html($page));

        $this->get('/portal/theme.css?project='.$projectId)->assertOk();
    }

    #[Test]
    public function a_known_phone_gets_one_code_and_a_stranger_gets_the_very_same_answer_and_nothing(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);

        $known = $this->post($this->loginUrl($projectId), ['phone' => self::PHONE]);
        $known->assertOk()->assertSee('un code à 6 chiffres vient d\'être envoyé', false);
        self::assertCount(1, $this->sent());
        self::assertMatchesRegularExpression('/\b\d{6}\b/', $this->sentCode());

        $stranger = $this->post($this->loginUrl($projectId), ['phone' => '+237699000000']);

        self::assertSame($this->statusOf($known), $this->statusOf($stranger));
        self::assertSame(
            str_replace(self::PHONE, '{phone}', $this->html($known)),
            str_replace('+237699000000', '{phone}', $this->html($stranger)),
            'The page must not tell a customer from a stranger.',
        );
        self::assertCount(1, $this->sent(), 'Nothing is sent to an unknown phone.');
        self::assertSame(1, DB::table('portal_login_codes')->count());
    }

    #[Test]
    public function the_code_is_stored_only_as_a_keyed_hash_and_the_message_names_the_projects_session(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);

        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE])->assertOk();
        $code = $this->sentCode();

        $row = DB::table('portal_login_codes')->first();
        self::assertNotNull($row);
        self::assertSame(64, strlen($this->scalar($row->code_hash)));
        self::assertSame(64, strlen($this->scalar($row->contact_hash)));
        self::assertNotSame($code, $row->code_hash);
        self::assertStringNotContainsString($code, (string) json_encode($row));
        self::assertStringNotContainsString('657467941', (string) json_encode($row));
        self::assertEquals(0, $row->attempts);

        $payload = $this->sent()[0]->data();
        self::assertSame($projectId.'_ecole', $payload['session']);
        self::assertStringContainsString('657467941', $this->scalar($payload['chatId']));
    }

    #[Test]
    public function the_right_code_opens_a_portal_session_and_the_home_is_reachable(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE, 'Auto-école Soleil');
        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE])->assertOk();

        $response = $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => $this->sentCode()]);
        $response->assertRedirect('/portal');

        $cookie = collect($response->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'portal');
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());

        $this->withUnencryptedCookie('portal', (string) $cookie->getValue())->get('/portal')->assertOk()->assertSee('Mon Auto-École');

        $session = DB::table('portal_sessions')->first();
        self::assertNotNull($session);
        self::assertSame('AE0001', $session->customer_ref);
        self::assertSame($projectId, $session->project_id);
        self::assertNull($session->return_url);

        self::assertSame(1, DB::table('audit_log')->where('action', 'portal.session_opened')->count());
    }

    #[Test]
    public function a_code_works_once(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);
        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE]);
        $code = $this->sentCode();

        $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => $code])->assertRedirect('/portal');
        $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => $code])->assertStatus(422)->assertSee('incorrect ou n\'est plus valide');

        self::assertSame(1, DB::table('portal_sessions')->count());
    }

    #[Test]
    public function an_expired_code_is_refused(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);
        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE]);
        $code = $this->sentCode();

        $this->advance('PT10M1S');
        $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => $code])->assertStatus(422);
        self::assertSame(0, DB::table('portal_sessions')->count());
    }

    #[Test]
    public function a_code_still_works_just_before_it_expires(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);
        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE]);
        $code = $this->sentCode();

        $this->advance('PT9M59S');
        $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => $code])->assertRedirect('/portal');
    }

    #[Test]
    public function five_wrong_attempts_kill_the_code_even_for_the_right_one(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);
        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE]);
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => $wrong])->assertStatus(422);
        }

        self::assertEquals(5, DB::table('portal_login_codes')->value('attempts'));
        $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => $code])->assertStatus(422);
        self::assertSame(0, DB::table('portal_sessions')->count());
    }

    #[Test]
    public function the_right_code_on_the_fifth_attempt_still_counts(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);
        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE]);
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 4; $i++) {
            $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => $wrong])->assertStatus(422);
        }

        $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => $code])->assertRedirect('/portal');
    }

    #[Test]
    public function a_malformed_code_is_refused_like_any_other(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);
        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE]);

        foreach (['', '12345', '1234567', 'abcdef', '12 34 5x'] as $bad) {
            $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => $bad])->assertStatus(422);
        }

        // A malformed guess spends nothing of the real code.
        self::assertEquals(0, DB::table('portal_login_codes')->value('attempts'));
    }

    #[Test]
    public function a_resend_within_a_minute_gets_the_neutral_answer_and_sends_nothing(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);

        $first = $this->post($this->loginUrl($projectId), ['phone' => self::PHONE]);
        $firstCode = $this->sentCode();
        $this->advance('PT30S');
        $again = $this->post($this->loginUrl($projectId), ['phone' => self::PHONE]);

        self::assertSame($this->statusOf($first), $this->statusOf($again));
        self::assertSame($this->html($first), $this->html($again));
        self::assertCount(1, $this->sent());

        // After a minute a new code leaves, and it retires the old one.
        $this->advance('PT31S');
        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE])->assertOk();
        self::assertCount(2, $this->sent());

        $newCode = $this->sentCode();
        if ($newCode !== $firstCode) {
            $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => $firstCode])->assertStatus(422);
        }
        $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => $newCode])->assertRedirect('/portal');
    }

    #[Test]
    public function a_phone_is_limited_to_five_codes_an_hour(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);

        for ($i = 0; $i < 5; $i++) {
            $this->post($this->loginUrl($projectId), ['phone' => self::PHONE])->assertOk();
            $this->advance('PT61S');
        }

        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE])->assertStatus(429);
        self::assertCount(5, $this->sent());

        // The wall is the same for a stranger's phone: it depends on the input only.
        for ($i = 0; $i < 5; $i++) {
            $this->post($this->loginUrl($projectId), ['phone' => '+237699111111'])->assertOk();
        }
        $this->post($this->loginUrl($projectId), ['phone' => '+237699111111'])->assertStatus(429);
    }

    #[Test]
    public function an_address_is_limited_per_hour(): void
    {
        config(['portal.direct_login.codes_per_ip_per_hour' => 3]);
        [, $projectId] = $this->project();

        foreach (['+237670000001', '+237670000002', '+237670000003'] as $phone) {
            $this->post($this->loginUrl($projectId), ['phone' => $phone])->assertOk();
        }

        $this->post($this->loginUrl($projectId), ['phone' => '+237670000004'])->assertStatus(429);
    }

    #[Test]
    public function a_project_cannot_send_more_codes_than_its_hourly_cap_and_the_browser_cannot_tell(): void
    {
        config(['portal.direct_login.project_hourly_cap' => 2]);
        [, $projectId] = $this->project();

        $answers = [];
        foreach (['+237670000001', '+237670000002', '+237670000003'] as $i => $phone) {
            $this->customer($projectId, 'C'.$i, $phone);
            $answers[] = $this->post($this->loginUrl($projectId), ['phone' => $phone]);
        }

        self::assertCount(2, $this->sent(), 'The third message is held back by the cap.');
        self::assertSame(200, $this->statusOf($answers[2]));
        self::assertSame(
            str_replace('+237670000003', '{phone}', $this->html($answers[2])),
            str_replace('+237670000001', '{phone}', $this->html($answers[0])),
        );
    }

    #[Test]
    public function the_checks_of_a_code_are_limited_per_address(): void
    {
        config(['portal.direct_login.verifications_per_ip_per_hour' => 3]);
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);

        for ($i = 0; $i < 3; $i++) {
            $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => '123456'])->assertStatus(422);
        }

        $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => '123456'])->assertStatus(429);
    }

    #[Test]
    public function when_the_counters_cannot_be_read_nothing_is_sent_and_nobody_gets_in(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);

        $this->app->bind(LoginThrottle::class, static fn (): LoginThrottle => new class implements LoginThrottle
        {
            public function admitRequest(string $projectId, string $contactHash, ?string $ip): bool
            {
                throw new LoginThrottleUnavailable('down');
            }

            public function admitVerification(string $projectId, ?string $ip): bool
            {
                throw new LoginThrottleUnavailable('down');
            }

            public function admitProjectSend(string $projectId): bool
            {
                throw new LoginThrottleUnavailable('down');
            }
        });

        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE])->assertStatus(503);
        $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => '123456'])->assertStatus(503);

        Http::assertNothingSent();
        self::assertSame(0, DB::table('portal_login_codes')->count());
    }

    #[Test]
    public function the_cache_throttle_fails_closed_when_the_cache_throws(): void
    {
        /** @var Repository&MockInterface $cache */
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('add', 'get', 'put', 'increment', 'has', 'forget')->andThrow(new RuntimeException('redis is down'));
        $throttle = new CacheLoginThrottle(new RateLimiter($cache));

        foreach ([
            static fn () => $throttle->admitRequest('p', 'h', '1.2.3.4'),
            static fn () => $throttle->admitVerification('p', '1.2.3.4'),
            static fn () => $throttle->admitProjectSend('p'),
        ] as $call) {
            try {
                $call();
                self::fail('A broken counter must refuse, not allow.');
            } catch (LoginThrottleUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_customer_of_another_project_cannot_log_in_on_this_projects_page(): void
    {
        [, $projectA] = $this->project();
        [, $projectB] = $this->project();
        $this->customer($projectB, 'B0001', '+237677000000');

        // B's customer on A's page: unknown there, nothing leaves.
        $this->post($this->loginUrl($projectA), ['phone' => '+237677000000'])->assertOk();
        self::assertCount(0, $this->sent());

        // B's real code does not open A's portal either.
        $this->post($this->loginUrl($projectB), ['phone' => '+237677000000'])->assertOk();
        self::assertCount(1, $this->sent());
        $this->post($this->loginUrl($projectA).'/verify', ['phone' => '+237677000000', 'login_pin' => $this->sentCode()])->assertStatus(422);
        self::assertSame(0, DB::table('portal_sessions')->count());
    }

    #[Test]
    public function an_erased_customer_cannot_log_in(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);
        $this->erase($projectId, 'AE0001');

        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE])->assertOk();
        self::assertCount(0, $this->sent());
        self::assertSame(0, DB::table('portal_login_codes')->count());
    }

    #[Test]
    public function a_customer_erased_after_the_code_was_sent_cannot_use_it(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);
        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE]);
        $code = $this->sentCode();

        $this->erase($projectId, 'AE0001');

        $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => $code])->assertStatus(422);
        self::assertSame(0, DB::table('portal_sessions')->count());
    }

    #[Test]
    public function a_phone_shared_by_two_customers_receives_nothing(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);
        $this->customer($projectId, 'AE0002', self::PHONE);

        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE])->assertOk();

        self::assertCount(0, $this->sent());
    }

    #[Test]
    public function the_phone_is_matched_however_it_is_typed(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);

        $this->post($this->loginUrl($projectId), ['phone' => ' +237 6 57-46.79 41 '])->assertOk();

        self::assertCount(1, $this->sent());
    }

    #[Test]
    public function something_that_is_not_a_phone_is_asked_again(): void
    {
        [, $projectId] = $this->project();

        foreach (['', 'abc', '12', '<script>alert(1)</script>'] as $bad) {
            $this->post($this->loginUrl($projectId), ['phone' => $bad])->assertStatus(422)->assertSee('numéro de téléphone valide');
        }

        self::assertSame(0, DB::table('portal_login_codes')->count());
    }

    #[Test]
    public function the_lookup_by_email_is_scoped_to_the_project_and_skips_the_erased(): void
    {
        [, $projectA] = $this->project();
        [, $projectB] = $this->project();
        $find = app(FindCustomerByContact::class);
        $upsert = static fn (string $projectId, string $ref, string $email) => app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: $ref, displayName: 'Client', email: $email, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
        $upsert($projectA, 'A1', 'Ali@Example.cm');
        $upsert($projectB, 'B1', 'bob@example.cm');

        $command = static fn (string $projectId, string $value): FindCustomerByContactCommand => new FindCustomerByContactCommand($projectId, FindCustomerByContactCommand::KIND_EMAIL, $value);

        self::assertSame('A1', $find->find($command($projectA, ' ali@example.CM'), AccessScope::forProject($projectA))?->externalRef);
        self::assertNull($find->find($command($projectA, 'bob@example.cm'), AccessScope::forProject($projectA)));
        self::assertNull($find->find($command($projectA, 'ali@example.cm'), AccessScope::forProject($projectB)), 'A scope outside the project finds nothing.');
        self::assertNull($find->find(new FindCustomerByContactCommand($projectA, 'fax', 'x'), AccessScope::forProject($projectA)));
        self::assertNull($find->find($command($projectA, ''), AccessScope::forProject($projectA)));

        $this->erase($projectA, 'A1');
        self::assertNull($find->find($command($projectA, 'ali@example.cm'), AccessScope::forProject($projectA)));
    }

    #[Test]
    public function a_session_opened_this_way_belongs_to_the_portal_only(): void
    {
        [, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', self::PHONE);
        $this->post($this->loginUrl($projectId), ['phone' => self::PHONE]);
        $response = $this->post($this->loginUrl($projectId).'/verify', ['phone' => self::PHONE, 'login_pin' => $this->sentCode()]);
        $cookie = collect($response->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'portal');
        self::assertNotNull($cookie);

        // The cookie is the portal's: it opens no console page and no API route.
        $this->withUnencryptedCookie('portal', (string) $cookie->getValue())->getJson('/v1/customers/AE0001')->assertStatus(401);
        $this->withUnencryptedCookie('portal', (string) $cookie->getValue())->get('/admin')->assertRedirect();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new DirectLoginTestClock(new DateTimeImmutable('2026-11-01T08:00:00Z'));
        $clock = $this->clock;
        $this->app->bind(Clock::class, static fn (): Clock => $clock);

        config(['providers.notification.whatsapp_waha.base_url' => 'https://waha.test']);
        Http::preventStrayRequests();
        // One provider message id per message: the notification table keeps them unique.
        Http::fake([self::WAHA => static fn () => Http::response(['id' => 'msg-'.Str::random(12)], 201)]);
    }

    private function advance(string $interval): void
    {
        $this->clock->moment = $this->clock->moment->add(new DateInterval($interval));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function project(bool $enabled = true): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project;
        $token = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project->id, ['portal:write']), actor: 'service:test', correlationId: null)->plaintextToken;

        app(ChannelSessionRepository::class)->save(new ChannelSession((string) Str::uuid(), $project->id, 'whatsapp', 'ecole', new DateTimeImmutable));

        if ($enabled) {
            $this->putJson('/v1/portal/settings', [
                'display_name' => 'Mon Auto-École',
                'direct_login_enabled' => true,
                'direct_login_session' => 'ecole',
            ], ['Authorization' => "Bearer {$token}"])->assertOk();
        }

        return [$token, $project->id];
    }

    private function customer(string $projectId, string $ref, string $phone, string $name = 'Client'): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: $ref, displayName: $name, email: null, phone: $phone,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
    }

    private function erase(string $projectId, string $ref): void
    {
        app(EraseCustomer::class)->erase(new EraseCustomerCommand($projectId, $ref, 'service:test', null));
    }

    private function loginUrl(string $projectId): string
    {
        return '/portal/'.$projectId.'/login';
    }

    /**
     * The WhatsApp messages that left, oldest first.
     *
     * @return list<Request>
     */
    private function sent(): array
    {
        $requests = [];

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'sendText')) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /**
     * The six digits of the last message that left.
     */
    private function sentCode(): string
    {
        $messages = $this->sent();
        self::assertNotEmpty($messages, 'No message left.');
        $last = end($messages);
        self::assertInstanceOf(Request::class, $last);
        $text = $last->data()['text'] ?? '';
        self::assertTrue(is_string($text) && preg_match('/\b(\d{6})\b/', $text, $found) === 1, 'The message carries a 6-digit code.');

        return $found[1];
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

    /**
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function statusOf($response): int
    {
        return $response->getStatusCode(); // @phpstan-ignore staticMethod.dynamicCall
    }

    private function scalar(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}

/**
 * A clock the test moves by hand (the same idea as SaasLifecycleFlowTest's).
 */
final class DirectLoginTestClock implements Clock
{
    public function __construct(public DateTimeImmutable $moment) {}

    public function now(): DateTimeImmutable
    {
        return $this->moment;
    }
}
