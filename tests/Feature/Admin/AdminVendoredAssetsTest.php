<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * Recette du 2026-10-29, F-05 — la console chargeait Tailwind et Alpine depuis deux CDN publics :
 * une compromission de l'un d'eux exécutait du JavaScript arbitraire sur l'origine de la console,
 * avec son jeton CSRF et les secrets qu'elle affiche une fois. Les fichiers sont désormais servis
 * par l'application ; ces tests empêchent le retour silencieux d'un hôte tiers ou d'une altération.
 */
final class AdminVendoredAssetsTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    private const ASSETS = [
        'tailwindcss-3.4.16.js' => '3f81aa7f6ecdb1acc14c202e513dfee00b6c7703cd81ce1be25bf5215a92e8cb',
        'alpine-csp-3.14.1.min.js' => '40f00d7aaa543eabd17c3a0f79ce2ac62e6be17caa9564527c20ec39152bb157',
    ];

    #[Test]
    public function the_vendored_libraries_are_the_pinned_files_unaltered(): void
    {
        foreach (self::ASSETS as $file => $sha256) {
            $path = public_path("assets/admin/vendor/{$file}");

            self::assertFileExists($path);
            self::assertSame($sha256, hash_file('sha256', $path), "{$file} differs from the audited file — see public/assets/admin/vendor/README.md");
        }
    }

    #[Test]
    public function the_layout_loads_both_libraries_from_its_own_origin(): void
    {
        $this->signInToConsole();
        $html = (string) $this->get('/admin')->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        foreach (array_keys(self::ASSETS) as $file) {
            self::assertStringContainsString('assets/admin/vendor/'.$file, $html);
        }
    }

    #[Test]
    public function the_standard_alpine_build_is_not_served_anymore(): void
    {
        // ADR-0104 — the standard build evaluates the expressions of the
        // templates with `new Function`, which the console's CSP forbids
        // (`'unsafe-eval'`). It is replaced by the CSP build, which reads
        // them as property paths. The file must not stay in `public/`:
        // it would remain downloadable, and could be swapped back for the
        // CSP build by a later commit.
        self::assertFileDoesNotExist(public_path('assets/admin/vendor/alpine-3.14.1.min.js'));
    }

    #[Test]
    public function no_page_of_the_console_loads_a_script_from_another_host(): void
    {
        $this->signInToConsole();

        foreach (['/admin', '/admin/projects', '/admin/operators', '/admin/audit-log'] as $path) {
            $html = (string) $this->get($path)->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall

            preg_match_all('/<script\b[^>]*\bsrc="([^"]+)"/i', $html, $scripts);
            foreach ($scripts[1] as $src) {
                self::assertDoesNotMatchRegularExpression('#^(https?:)?//(?!'.preg_quote((string) parse_url(url('/'), PHP_URL_HOST), '#').')#', $src, "{$path} loads a script from another host: {$src}");
            }
        }
    }

    #[Test]
    public function the_content_security_policy_forbids_third_party_script_hosts(): void
    {
        $this->signInToConsole();
        $csp = (string) $this->get('/admin')->assertOk()->headers->get('Content-Security-Policy');

        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertDoesNotMatchRegularExpression('#script-src[^;]*https?://#', $csp, 'a CDN must not be allowed back by the policy');
    }
}
