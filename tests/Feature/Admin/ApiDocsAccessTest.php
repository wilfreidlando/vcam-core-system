<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrustProxiesFromSidecar;
use Core\Identity\OperatorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * Audit de sécurité 2026-10-29, écart #14 : `Gate::define('viewApiDocs', fn () => true)` rendait
 * `/docs/api` public PARTOUT, alors que la page charge des scripts tiers sur l'origine de l'API.
 *
 * ADR-0049 assume une documentation publique pour un intégrateur : elle le reste en local et en
 * staging. En PRODUCTION, seul un opérateur connecté à la console, qui porte `audit:read`, l'ouvre.
 * Dans tous les environnements, ces pages ne peuvent pas être encadrées et ne divulguent pas
 * leur adresse par le `Referer`.
 */
final class ApiDocsAccessTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    /**
     * @return array<string, array{string}>
     */
    public static function openEnvironments(): array
    {
        return ['local' => ['local'], 'staging' => ['staging'], 'testing' => ['testing']];
    }

    #[Test]
    #[DataProvider('openEnvironments')]
    public function the_documentation_stays_public_outside_production(string $environment): void
    {
        $this->app['env'] = $environment;

        $this->get('/docs/api')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    #[Test]
    public function in_production_an_anonymous_visitor_is_refused(): void
    {
        $this->inProduction();

        $this->get('/docs/api')
            ->assertForbidden()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/docs/api.json')->assertForbidden();
    }

    #[Test]
    public function in_production_an_operator_signed_in_to_the_console_with_audit_read_opens_it(): void
    {
        $this->signInToConsole(OperatorRole::ReadOnlyAuditor);
        $this->inProduction();

        $this->get('/docs/api')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * APP_ENV=production refuses to serve without the sidecar token (ADR-0064): give it one, as nginx does.
     */
    private function inProduction(): void
    {
        $token = str_repeat('a', 64);

        config(['trustedproxy.sidecar_token' => $token]);
        $this->app['env'] = 'production';
        $this->withHeader(TrustProxiesFromSidecar::HEADER, $token);
    }

    #[Test]
    public function the_documentation_carries_its_own_security_headers(): void
    {
        // Audit 2026-10-29 #14 / G-1 — the pages used to carry only two
        // headers. The CSP names the two CDNs the bundles come from and
        // nothing else; the documentation describes the API, so it must not
        // sit in a cache either.
        $response = $this->get('/docs/api')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Cache-Control', 'no-store, private');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString('https://unpkg.com', $csp);
        self::assertStringContainsString('https://cdn.jsdelivr.net', $csp);

        // No other host may run a script there.
        preg_match("/script-src ([^;]+);/", $csp, $scriptSrc);
        $allowed = array_map('trim', explode(' ', $scriptSrc[1] ?? ''));
        foreach ($allowed as $source) {
            self::assertContains(
                $source,
                ["'self'", "'unsafe-inline'", 'https://unpkg.com', 'https://cdn.jsdelivr.net'],
                "A source non prévue est autorisée dans script-src : {$source}",
            );
        }
    }

    #[Test]
    public function the_third_party_bundles_of_the_documentation_are_pinned(): void
    {
        // `integrity` pins the content of the bundles cryptographically: a
        // compromised CDN can no longer execute on the API's origin. The
        // version must stay exact for the hash to hold — `@latest` would
        // break it (and was itself a mutable dependency).
        $html = (string) $this->get('/docs/api')->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        preg_match_all('/<(script|link)\b[^>]*>/', $html, $tags);
        $thirdParty = 0;

        foreach ($tags[0] as $tag) {
            if (! str_contains($tag, 'https://')) {
                continue;
            }

            $thirdParty++;
            self::assertStringContainsString('integrity="sha384-', $tag, "Bundle tiers sans empreinte : {$tag}");
            self::assertStringContainsString('crossorigin="anonymous"', $tag, "Bundle tiers sans crossorigin : {$tag}");
            self::assertDoesNotMatchRegularExpression('/@\b(latest|next)\b/', $tag, "Bundle tiers sur une version mutable : {$tag}");
        }

        self::assertGreaterThan(0, $thirdParty, 'the documentation loads third-party bundles');
    }
}
