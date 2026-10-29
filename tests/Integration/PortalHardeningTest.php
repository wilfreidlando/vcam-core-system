<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
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
use Core\Portal\Domain\Model\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit de sécurité 2026-10-29 (#10, #11) et recette F-12 côté portail :
 *  - `GET /portal/theme.css?project=` n'a plus de 500 à portée de n'importe qui, et est plafonné ;
 *  - `logo_url` / `support_url` ne pointent que vers une origine que le PROJET a déclarée ;
 *  - `trial_days: "14"` ne devient plus `null` en silence, un booléen écrit en chaîne ne plante plus.
 */
final class PortalHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const CDN = 'https://cdn.mon-saas.cm';

    // ------------------------------------------------------------------ #11 — theme.css

    #[Test]
    #[DataProvider('hostileProjects')]
    public function the_public_stylesheet_never_answers_500_to_a_malformed_project(string $project): void
    {
        $this->get('/portal/theme.css?project='.$project)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=utf-8')
            ->assertSee('--brand:#2563eb', false);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileProjects(): array
    {
        return [
            'trente-six tirets' => [str_repeat('-', 36)],
            'hexadécimal sans forme d\'uuid' => [str_repeat('a', 36)],
            'trop court' => ['abc'],
            'uuid inconnu' => ['6f1c1f0e-0000-4000-8000-000000000000'],
        ];
    }

    #[Test]
    public function the_public_stylesheet_is_throttled(): void
    {
        $route = Route::getRoutes()->getByName('portal.theme');
        self::assertNotNull($route);
        self::assertContains('throttle:portal-theme', $route->gatherMiddleware());

        $last = 0;
        for ($i = 0; $i < 241; $i++) {
            $last = $this->get('/portal/theme.css')->getStatusCode();
        }

        self::assertSame(429, $last, 'the 241st request of the minute is refused');
    }

    // ------------------------------------------------------------------ #10 — allowed_asset_origins

    #[Test]
    public function an_external_logo_is_refused_until_the_project_declares_its_origin(): void
    {
        [$token] = $this->project(['portal:write']);

        // Empty list = no external URL: the project must declare the origin first.
        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil', 'logo_url' => self::CDN.'/logo.png'], $this->auth($token))
            ->assertStatus(422);
        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil', 'support_url' => 'https://help.mon-saas.cm/aide'], $this->auth($token))
            ->assertStatus(422);

        // A mailto: address fetches nothing and needs no origin.
        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil', 'support_url' => 'mailto:aide@mon-saas.cm'], $this->auth($token))
            ->assertOk();

        $this->putJson('/v1/portal/settings', [
            'display_name' => 'Soleil',
            'logo_url' => self::CDN.'/logo.png',
            'support_url' => 'https://help.mon-saas.cm/aide',
            'allowed_asset_origins' => [self::CDN, 'https://help.mon-saas.cm'],
        ], $this->auth($token))
            ->assertOk()
            ->assertJsonPath('logo_url', self::CDN.'/logo.png')
            ->assertJsonPath('allowed_asset_origins', [self::CDN, 'https://help.mon-saas.cm']);
    }

    #[Test]
    public function another_host_is_refused_even_when_an_origin_is_declared(): void
    {
        [$token] = $this->project(['portal:write']);

        foreach ([
            'https://tracker.evil.example/pixel.gif',
            'https://cdn.mon-saas.cm.evil.example/logo.png',
            'https://evil.example/https://cdn.mon-saas.cm/logo.png',
            self::CDN.':8443/logo.png',
            'http://cdn.mon-saas.cm/logo.png',
        ] as $logo) {
            $this->putJson('/v1/portal/settings', [
                'display_name' => 'Soleil', 'logo_url' => $logo, 'allowed_asset_origins' => [self::CDN],
            ], $this->auth($token))->assertStatus(422);
        }
    }

    #[Test]
    #[DataProvider('badOrigins')]
    public function an_origin_must_be_a_bare_https_origin(string $origin): void
    {
        [$token] = $this->project(['portal:write']);

        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil', 'allowed_asset_origins' => [$origin]], $this->auth($token))
            ->assertStatus(422);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badOrigins(): array
    {
        return [
            'http' => ['http://cdn.mon-saas.cm'],
            'avec chemin' => ['https://cdn.mon-saas.cm/assets'],
            'avec slash final' => ['https://cdn.mon-saas.cm/'],
            'avec requête' => ['https://cdn.mon-saas.cm?x=1'],
            'sans schéma' => ['cdn.mon-saas.cm'],
            'avec identifiants' => ['https://user:pass@cdn.mon-saas.cm'],
        ];
    }

    #[Test]
    public function a_later_save_that_omits_the_list_keeps_it(): void
    {
        [$token] = $this->project(['portal:write']);

        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil', 'logo_url' => self::CDN.'/logo.png', 'allowed_asset_origins' => [self::CDN]], $this->auth($token))->assertOk();
        // The console saves without knowing the list: the logo must still be valid, the list unchanged.
        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil 2', 'logo_url' => self::CDN.'/logo.png'], $this->auth($token))
            ->assertOk()
            ->assertJsonPath('allowed_asset_origins', [self::CDN]);
    }

    #[Test]
    public function a_logo_saved_before_the_list_existed_is_not_rendered_until_its_origin_is_declared(): void
    {
        [$token, $projectId] = $this->project(['portal:write']);
        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil'], $this->auth($token))->assertOk();
        // The legacy state: a logo on any host, saved under the old rule.
        DB::table('portal_settings')->where('project_id', $projectId)->update(['logo_url' => 'https://tracker.evil.example/pixel.gif']);
        $cookie = $this->login($token, $projectId);

        $page = $this->withUnencryptedCookie('portal', $cookie)->get('/portal')->assertOk();
        $page->assertDontSee('brand__logo', false);
        $page->assertDontSee('tracker.evil.example', false);

        DB::table('portal_settings')->where('project_id', $projectId)->update(['allowed_asset_origins' => json_encode(['https://tracker.evil.example'])]);
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal')->assertOk()->assertSee('brand__logo', false);
    }

    #[Test]
    public function the_domain_hides_what_the_project_never_declared(): void
    {
        $legacy = new PortalSettings(
            projectId: (string) Str::uuid(), displayName: 'X', logoUrl: 'https://tracker.evil.example/p.gif', primaryColor: '#2563eb',
            supportUrl: 'https://tracker.evil.example/help', locale: 'fr', allowedReturnUrls: [], allowSelfCancel: false,
            allowPlanChange: false, paymentChannels: [],
        );

        self::assertNull($legacy->publicLogoUrl());
        self::assertNull($legacy->publicSupportUrl());

        $mail = new PortalSettings(
            projectId: (string) Str::uuid(), displayName: 'X', logoUrl: null, primaryColor: '#2563eb',
            supportUrl: 'mailto:aide@mon-saas.cm', locale: 'fr', allowedReturnUrls: [], allowSelfCancel: false,
            allowPlanChange: false, paymentChannels: [],
        );

        self::assertSame('mailto:aide@mon-saas.cm', $mail->publicSupportUrl());
    }

    // ------------------------------------------------------------------ F-12 — casts

    #[Test]
    public function a_trial_written_as_a_string_still_gives_the_trial(): void
    {
        [$token, $projectId] = $this->project(['portal:write', 'customers:write', 'subscriptions:write']);
        $this->plan($projectId);

        $this->postJson('/v1/portal/sessions', [
            'customer_ref' => 'AE0001', 'customer' => ['display_name' => 'Soleil'], 'plan_code' => 'pro', 'trial_days' => '14',
        ], $this->auth($token))->assertStatus(201)->assertJsonPath('subscription_created', true);

        // Before: "14" became null and the contract was created WITHOUT a trial, with no error.
        self::assertSame(1, DB::table('subscriptions')->count());
        self::assertSame('trialing', DB::table('subscriptions')->value('status'));
        self::assertNotNull(DB::table('subscriptions')->value('trial_ends_at'));
    }

    #[Test]
    #[DataProvider('badTrials')]
    public function a_trial_that_is_not_a_whole_number_of_days_is_a_422_and_creates_nothing(mixed $days): void
    {
        [$token, $projectId] = $this->project(['portal:write', 'customers:write', 'subscriptions:write']);
        $this->plan($projectId);

        $this->postJson('/v1/portal/sessions', [
            'customer_ref' => 'AE0001', 'customer' => ['display_name' => 'Soleil'], 'plan_code' => 'pro', 'trial_days' => $days,
        ], $this->auth($token))->assertStatus(422);

        self::assertSame(0, DB::table('subscriptions')->count());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badTrials(): array
    {
        return ['texte' => ['abc'], 'décimal' => ['14.5'], 'flottant' => [14.5], 'trop long' => ['400'], 'négatif' => ['-1'], 'tableau' => [[14]]];
    }

    #[Test]
    public function booleans_written_as_strings_are_cast_and_anything_else_is_a_422(): void
    {
        [$token] = $this->project(['portal:write']);

        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil', 'allow_self_cancel' => '1', 'allow_plan_change' => 0, 'direct_login_enabled' => '0'], $this->auth($token))
            ->assertOk()
            ->assertJsonPath('allow_self_cancel', true)
            ->assertJsonPath('allow_plan_change', false)
            ->assertJsonPath('direct_login_enabled', false);

        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil', 'allow_self_cancel' => 'peut-être'], $this->auth($token))->assertStatus(422);
        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil', 'allow_plan_change' => ['oui']], $this->auth($token))->assertStatus(422);
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

    private function plan(string $projectId): void
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: $projectId, code: 'pro', name: 'Pro', description: null, visibility: 'public',
            sortOrder: 0, actor: 'test', correlationId: null,
        ));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId, planCode: 'pro', priceMinor: 25_000, currency: 'XAF', intervalUnit: 'month',
            intervalCount: 1, trialDays: 0, graceDays: 7, graceAccess: true,
            features: ['students' => ['type' => 'limit', 'meter' => 'students', 'limit' => 100, 'reset' => 'never', 'overage' => 'block']],
            actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: $projectId, planCode: 'pro', version: $version->version, actor: 'test', correlationId: null,
        ));
    }

    private function login(string $token, string $projectId): string
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: 'AE0001', displayName: 'Soleil', email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));

        $url = $this->postJson('/v1/portal/sessions', ['customer_ref' => 'AE0001'], $this->auth($token))->assertStatus(201)->json('url');
        self::assertIsString($url);

        $response = $this->get((string) parse_url($url, PHP_URL_PATH));
        $cookie = collect($response->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'portal');

        return $cookie?->getValue() ?? '';
    }
}
