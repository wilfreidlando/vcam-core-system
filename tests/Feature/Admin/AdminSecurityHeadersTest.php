<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Http\Middleware\AdminSecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * ADR-0081 S-11 — « console, portail » : les en-têtes de sécurité du navigateur. Le portail
 * les avait (et son test) ; la console, qui bouge l'argent, n'en avait aucun (recette du
 * 2026-10-29, F-04, annexe C n° 1). Un opérateur pouvait être piégé par une iframe invisible.
 */
final class AdminSecurityHeadersTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function the_login_page_cannot_be_framed_and_does_not_leak_its_url(): void
    {
        $this->assertSecure($this->get('/admin/login')->assertOk());
    }

    #[Test]
    public function every_authenticated_page_carries_the_headers(): void
    {
        $this->signInToConsole();

        foreach (['/admin', '/admin/projects', '/admin/operators', '/admin/audit-log', '/admin/payments'] as $path) {
            $this->assertSecure($this->get($path)->assertOk());
        }
    }

    #[Test]
    public function a_redirect_is_protected_too(): void
    {
        // The headers must not depend on the response being a 200: an unauthenticated request
        // is redirected to the login page, and that response is the first thing a framing
        // attack would see.
        $this->assertSecure($this->get('/admin/projects')->assertRedirect());
    }

    #[Test]
    public function the_csp_names_the_directives_that_cannot_break_a_page(): void
    {
        foreach (['frame-ancestors \'none\'', 'form-action \'self\'', 'base-uri \'self\'', 'object-src \'none\'', 'script-src \'self\''] as $directive) {
            self::assertStringContainsString($directive, AdminSecurityHeaders::CSP);
        }
    }

    #[Test]
    public function the_script_policy_forbids_an_inline_script(): void
    {
        // ADR-0102 — `'unsafe-inline'` is gone from `script-src`: the
        // layout's only inline script (the mobile-nav store) is now a file
        // served by the application, and no view carries an inline `on*=`
        // handler. A script can therefore only come from our own origin.
        //
        // `'unsafe-eval'` stays until Alpine's CSP build is integrated
        // (ADR-0102, décision 3 bis) — the standard build evaluates the
        // expressions of the templates with `new Function`.
        self::assertStringNotContainsString('\'unsafe-inline\'', self::scriptSource());
        self::assertStringContainsString('\'unsafe-eval\'', self::scriptSource());
    }

    #[Test]
    public function no_page_of_the_console_carries_an_inline_script_or_an_inline_handler(): void
    {
        $this->signInToConsole();

        foreach (['/admin', '/admin/projects', '/admin/operators', '/admin/audit-log', '/admin/payments'] as $path) {
            // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
            $html = (string) $this->get($path)->assertOk()->getContent();

            // A <script> without a src: an inline script.
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html, "{$path} carries an inline script");
            // An on*= handler attribute.
            self::assertDoesNotMatchRegularExpression('/\son(click|change|submit|input|load|error|mouseover|focus)\s*=/i', $html, "{$path} carries an inline handler");
        }
    }

    /** The value of the `script-src` directive, whatever its position in the policy. */
    private static function scriptSource(): string
    {
        self::assertSame(1, preg_match('/script-src ([^;]+)/', AdminSecurityHeaders::CSP, $match));

        return $match[1] ?? '';
    }

    /**
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertSecure($response): void
    {
        $response->assertHeader('Content-Security-Policy', AdminSecurityHeaders::CSP);
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        // A page that may show an API key in clear must not be cached.
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
