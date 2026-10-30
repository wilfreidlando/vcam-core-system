<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * Recette du 2026-10-29 (registre `docs/audit/ecarts-recette-2026-10-29.md`) :
 * le layout de la console avait quatre défauts qu'aucun test ne voyait, parce que
 * aucun ne regardait ce que le navigateur reçoit — F-01 (menu mobile jamais ouvert),
 * F-02 (expression Alpine invalide), F-07 (rôle en valeur technique), F-11 (erreurs
 * de validation jetées), et les écarts d'accessibilité A1–A8, C19, H13.
 */
final class ConsoleLayoutTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function the_hamburger_and_the_sidebar_read_the_same_store(): void
    {
        $this->signInToConsole();
        $html = $this->page('/admin');

        // F-01 — the button used to toggle a local `open` flag that nothing read.
        self::assertStringContainsString('@click="$store.nav.toggle()"', $html);
        self::assertStringContainsString(':class="{ \'is-open\': $store.nav.open }"', $html);
        self::assertStringNotContainsString('x-data="{ open: false }"', $html);
        self::assertStringNotContainsString('$store.navOpen', $html, 'the dead store must not come back');

        // The store itself is defined, with the methods the templates call.
        // It lives in a file served by the application, not in an inline
        // <script>: an inline script would force 'unsafe-inline' into the
        // console's CSP (ADR-0102). The layout loads it BEFORE Alpine —
        // the store is read by the very first render of the drawer, and
        // `alpine:init` fires when Alpine starts.
        self::assertStringContainsString('assets/admin/console.js', $html);
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>.*?Alpine\.store/s', $html, 'the store must not be in an inline script');

        $store = (string) file_get_contents(public_path('assets/admin/console.js'));
        self::assertStringContainsString("Alpine.store('nav'", $store);
        foreach (['toggle()', 'close()', 'sync()'] as $method) {
            self::assertStringContainsString($method, $store);
        }

        // Loaded before Alpine: both are deferred, the document order decides.
        self::assertLessThan(
            strpos($html, 'assets/admin/vendor/alpine-3.14.1.min.js'),
            strpos($html, 'assets/admin/console.js'),
            'the store must be loaded before Alpine, or `alpine:init` has already fired',
        );
    }

    #[Test]
    public function the_toggle_is_announced_to_assistive_technology(): void
    {
        $this->signInToConsole();
        $html = $this->page('/admin');

        // A4 — a toggle that opens something says whether it is open and what it controls.
        self::assertStringContainsString(':aria-expanded="$store.nav.open', $html);
        self::assertStringContainsString('aria-controls="admin-sidebar"', $html);
        self::assertStringContainsString('id="admin-sidebar"', $html);
    }

    #[Test]
    public function no_alpine_expression_of_the_layout_is_an_assignment_inside_a_logical_chain(): void
    {
        $this->signInToConsole();
        $html = $this->page('/admin');

        // F-02 — `window.innerWidth >= 768 && $store.navOpen = true` is a SyntaxError
        // (« Invalid left-hand side in assignment »): `a && b = c` is not JavaScript.
        // It threw on every resize and the drawer never closed. We cannot run a JS
        // parser here, so we look for the shape of the mistake: an assignment right
        // after `&&` or `||`, in any Alpine directive.
        preg_match_all('/(?:@[\w.:-]+|:[\w-]+|x-[\w:.-]+)="([^"]*)"/', $html, $matches);
        $offenders = [];
        foreach ($matches[1] as $expression) {
            $expression = html_entity_decode($expression, ENT_QUOTES);
            if (preg_match('/(?:&&|\|\|)\s*[\w$.\[\]()\'"]+\s*=(?![=>])/', $expression) === 1) {
                $offenders[] = $expression;
            }
        }

        self::assertSame([], $offenders, 'an assignment may not follow && or || in an Alpine expression');
    }

    #[Test]
    public function a_validation_error_is_shown_to_the_operator(): void
    {
        $this->signInToConsole();
        $projectId = $this->aProject();

        // F-11 — the layout rendered session('status') and session('error') but not $errors:
        // back()->withErrors() produced a flash that nothing displayed, and twenty forms
        // « looked like they did nothing ».
        $page = $this->from("/admin/projects/{$projectId}/plans/create")
            ->followingRedirects()
            ->post("/admin/projects/{$projectId}/plans", ['code' => 'MAJUSCULES', 'name' => 'Plan de test', 'visibility' => 'public']);

        $page->assertOk();
        $page->assertSee('role="alert"', false);
        $page->assertSee("Le formulaire n'a pas pu être enregistré", false);
    }

    #[Test]
    public function the_console_has_a_skip_link_and_a_named_main_landmark(): void
    {
        $this->signInToConsole();
        $html = $this->page('/admin');

        // A6 — fifteen sidebar links and a breadcrumb come before the content of every page.
        self::assertStringContainsString('<a href="#main" class="skip-link">Aller au contenu</a>', $html);
        self::assertStringContainsString('<main id="main" tabindex="-1"', $html);

        // A1 — two <nav> on the page, both named.
        self::assertStringContainsString('<nav aria-label="Navigation principale"', $html);
    }

    #[Test]
    public function the_status_and_alert_regions_are_in_the_page_even_when_empty(): void
    {
        $this->signInToConsole();
        $html = $this->page('/admin');

        // H13 — a live region created together with its message is not announced: there was no
        // empty region to watch change. They must be in the DOM on every page.
        self::assertStringContainsString('role="status" aria-live="polite"', $html);
        self::assertMatchesRegularExpression('/<div class="[^"]*" role="alert">/', $html);
    }

    #[Test]
    public function the_focus_ring_meets_the_contrast_of_wcag_2_2(): void
    {
        $this->signInToConsole();
        $html = $this->page('/admin');

        // A7 — the 25 %-alpha halo measured 1.39:1 on white; SC 2.4.11 asks for 3:1.
        self::assertStringContainsString('outline: 2px solid #4338ca', $html);
        self::assertStringNotContainsString('0 0 0 3px rgb(99 102 241 / 0.25)', $html);
    }

    #[Test]
    public function motion_and_the_cloaked_overlays_are_handled(): void
    {
        $this->signInToConsole();
        $html = $this->page('/admin');

        // A8 — the portal honours prefers-reduced-motion, the console did not.
        self::assertStringContainsString('@media (prefers-reduced-motion: no-preference)', $html);
        // C19 — without this rule every confirmation overlay flashes before Alpine starts.
        self::assertStringContainsString('[x-cloak] { display: none !important; }', $html);
    }

    #[Test]
    public function the_operators_role_is_shown_in_french_not_as_its_technical_value(): void
    {
        $this->signInToConsole();
        $html = $this->page('/admin');

        // F-07 — « Rôle : platform_admin » was readable by a developer, not by an operator.
        self::assertStringContainsString('Administrateur de plateforme', $html);
        self::assertStringNotContainsString('platform_admin', $html);
    }

    #[Test]
    public function the_sidebar_links_say_where_they_go(): void
    {
        $this->signInToConsole();
        $html = $this->page('/admin');

        // F-06 — « Wallets » and « Facturation » both led to the list of projects, with a label
        // that promised a screen of their own.
        self::assertStringContainsString('Wallets par projet', $html);
        self::assertStringContainsString('Facturation par projet', $html);
    }

    #[Test]
    public function the_drawer_is_hidden_below_md_by_a_rule_and_not_by_a_class_conflict(): void
    {
        $this->signInToConsole();
        $html = $this->page('/admin');

        // The drawer is out of the tab order and the accessibility tree unless the store opens it.
        self::assertStringContainsString('#admin-sidebar { display: none; }', $html);
        self::assertStringContainsString('#admin-sidebar.is-open { display: flex; flex-direction: column; }', $html);
    }

    private function page(string $path): string
    {
        $content = $this->get($path)->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        return is_string($content) ? $content : '';
    }

    private function aProject(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet de test'), actor: 'service:test', correlationId: null)->project->id;
    }
}
