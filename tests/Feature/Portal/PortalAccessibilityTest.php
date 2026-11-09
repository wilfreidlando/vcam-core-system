<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use stdClass;
use Tests\TestCase;

/**
 * The customer portal is the repository's conformance template (strict CSP, no script, skip link,
 * accessible meters). This pins the seven residual accessibility gaps of the audit annex (G1 to G7),
 * the French agreement of the counts, and the way every form reports its errors.
 *
 * Two angles: a static read of every view, and the real rendering of the pages that carried a gap
 * (a view is rendered from plain fixtures; no database, no browser).
 */
final class PortalAccessibilityTest extends TestCase
{
    private const VIEWS = 'packages/portal/resources/views';

    #[Test]
    public function no_portal_view_ships_script_inline_handlers_or_javascript_urls(): void
    {
        foreach ($this->views() as $name => $source) {
            self::assertStringNotContainsStringIgnoringCase('<script', $source, $name);
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $source, $name.': inline event handler');
            self::assertStringNotContainsStringIgnoringCase('javascript:', $source, $name);
            self::assertDoesNotMatchRegularExpression('#(?:src|href)\s*=\s*"https?://#i', $source, $name.': no CDN or third-party asset');
        }
    }

    #[Test]
    public function no_view_leaves_an_empty_aria_attribute_or_a_ternary_that_can_produce_one(): void
    {
        foreach ($this->views() as $name => $source) {
            self::assertStringNotContainsString('aria-describedby=""', $source, $name);
            self::assertStringNotContainsString('aria-labelledby=""', $source, $name);
            self::assertDoesNotMatchRegularExpression('/aria-describedby="\{\{[^}]*\?[^}]*:\s*\'\'\s*\}\}"/', $source, $name.': a conditional that may yield an empty aria-describedby');
        }
    }

    #[Test]
    public function no_view_writes_a_plural_in_parentheses(): void
    {
        foreach ($this->views() as $name => $source) {
            self::assertDoesNotMatchRegularExpression('/\p{L}\(s\)/u', $source, $name.': use a real singular/plural');
        }
    }

    #[Test]
    public function every_labelledby_points_at_an_id_declared_in_the_same_view(): void
    {
        foreach ($this->views() as $name => $source) {
            preg_match_all('/aria-labelledby="([^"]+)"/', $source, $found);

            foreach ($found[1] as $target) {
                self::assertStringContainsString('id="'.$target.'"', $source, $name.': aria-labelledby="'.$target.'" has no matching id');
            }
        }
    }

    #[Test]
    public function the_forms_that_take_input_report_their_errors_as_alerts_tied_to_the_field(): void
    {
        $views = $this->views();

        foreach (['login.blade.php' => 'login-error', 'login-verify.blade.php' => 'code-error', 'invoice.blade.php' => 'pay-error'] as $file => $errorId) {
            $source = $views[$file];
            self::assertStringContainsString('role="alert"', $source, $file);
            self::assertStringContainsString('id="'.$errorId.'"', $source, $file);
            self::assertStringContainsString($errorId, (string) preg_replace('/^.*?<form/s', '<form', $source), $file.': the field must reference the error message');
            self::assertStringContainsString('aria-invalid="true"', $source, $file);
        }

        // The actions that carry no field (subscribe, change, cancel, keep, renew) answer with the error page.
        self::assertStringContainsString('role="alert"', $views['error.blade.php']);
    }

    #[Test]
    public function the_stylesheet_no_longer_injects_the_check_glyph_or_a_fake_static_button(): void
    {
        $css = (string) file_get_contents(base_path('public/assets/portal/portal.css'));

        self::assertStringNotContainsString('content: "✓"', $css, 'The glyph is in the markup, aria-hidden.');
        self::assertStringNotContainsString('.button.is-static', $css);
        self::assertStringContainsString('.tier__state', $css);
        self::assertStringContainsString('.sidebar__foot', $css);
    }

    #[Test]
    public function g1_the_pay_label_inside_the_invoice_link_is_hidden_from_assistive_technology(): void
    {
        $html = $this->render('home', ['home' => $this->home(1)]);

        self::assertStringContainsString('<span class="button button--sm" aria-hidden="true">Payer</span>', $html);
    }

    #[Test]
    public function g2_the_code_field_only_references_the_error_when_there_is_one(): void
    {
        $base = ['branding' => $this->branding(), 'projectId' => 'p1', 'phone' => '+237600000000'];

        $clean = $this->renderGuest('login-verify', $base);
        self::assertStringNotContainsString('aria-describedby', $clean);
        self::assertStringNotContainsString('aria-invalid', $clean);

        $failed = $this->renderGuest('login-verify', $base + ['error' => 'Code incorrect.']);
        self::assertStringContainsString('aria-describedby="code-error"', $failed);
        self::assertStringContainsString('aria-invalid="true"', $failed);
        self::assertStringContainsString('id="code-error" role="alert"', $failed);
    }

    #[Test]
    public function g3_the_shell_exposes_a_contentinfo_landmark(): void
    {
        $html = $this->render('invoices', ['invoices' => []]);

        self::assertStringContainsString('<footer class="sidebar__foot">', $html);
        self::assertStringContainsString('</footer>', $html);
        self::assertSame(1, substr_count($html, '<footer'));
        self::assertStringNotContainsString('<script', $html);
    }

    #[Test]
    public function g4_the_current_plan_is_a_status_text_not_a_disabled_looking_button(): void
    {
        $html = $this->render('plans', ['plans' => $this->plans()]);

        self::assertStringContainsString('<p class="tier__state">Offre actuelle</p>', $html);
        self::assertStringNotContainsString('is-static', $html);
        self::assertStringNotContainsString('button--ghost button--block', $html);
    }

    #[Test]
    public function g5_both_feature_states_have_a_text_alternative_and_the_glyph_is_hidden(): void
    {
        $on = $this->entitlement('boolean', 'priority_support', true);
        $off = $this->entitlement('boolean', 'custom.domain', false);
        $html = $this->render('usage', ['usage' => $this->usage([$on, $off])]);

        self::assertStringContainsString('<span class="sr-only">(inclus)</span>', $html);
        self::assertStringContainsString('<span class="sr-only">(non inclus)</span>', $html);
        self::assertSame(2, substr_count($html, 'class="ticks__mark" aria-hidden="true"'));
        self::assertStringContainsString('aria-labelledby="features-title"', $html);
    }

    #[Test]
    public function g6_each_subscription_section_is_named_by_its_own_heading(): void
    {
        $html = $this->render('subscriptions', ['subscriptions' => [$this->subscription('s1', 'pro'), $this->subscription('s2', 'basic')], 'nonce' => 'n']);

        foreach ([0, 1] as $i) {
            self::assertStringContainsString('aria-labelledby="sub-'.$i.'-title"', $html);
            self::assertStringContainsString('id="sub-'.$i.'-title"', $html);
        }
    }

    #[Test]
    public function g7_the_invoice_list_is_a_titled_section(): void
    {
        $html = $this->render('invoices', ['invoices' => [$this->invoice('i1')]]);

        self::assertStringContainsString('aria-labelledby="invoices-title"', $html);
        self::assertStringContainsString('id="invoices-title"', $html);
        self::assertMatchesRegularExpression('#<h2 id="invoices-title"[^>]*>Liste des factures</h2>#', $html);
    }

    #[Test]
    public function v07_pending_invoices_agree_in_number(): void
    {
        $one = $this->render('home', ['home' => $this->home(1)]);
        $two = $this->render('home', ['home' => $this->home(2)]);

        self::assertStringContainsString('1 facture en attente', $one);
        self::assertStringNotContainsString('factures en attente', $one);
        self::assertStringContainsString('2 factures en attente', $two);
        self::assertStringNotContainsString('(s)', $one.$two);
    }

    #[Test]
    public function an_unlimited_meter_agrees_in_number_too(): void
    {
        $one = $this->render('usage', ['usage' => $this->usage([$this->entitlement('limit', 'sms', true, used: 1)])]);
        $many = $this->render('usage', ['usage' => $this->usage([$this->entitlement('limit', 'sms', true, used: 12)])]);

        self::assertStringContainsString('1 utilisé · illimité', $one);
        self::assertStringContainsString('12 utilisés · illimité', $many);
        self::assertStringNotContainsString('(s)', $one.$many);
    }

    #[Test]
    public function the_error_page_announces_its_message(): void
    {
        $html = $this->renderGuest('error', ['title' => 'Action impossible', 'message' => 'Refusé.']);

        self::assertStringContainsString('<p role="alert">Refusé.</p>', $html);
    }

    // ---------------------------------------------------------------------------------------------
    // helpers

    /**
     * @return array<string, string> view file => source
     */
    private function views(): array
    {
        $out = [];
        $root = base_path(self::VIEWS);

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $out[ltrim(substr($file->getPathname(), strlen($root)), '/')] = (string) file_get_contents($file->getPathname());
            }
        }

        self::assertGreaterThanOrEqual(15, count($out), 'The portal views were not found.');

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $view, array $data): string
    {
        /** @var \Illuminate\View\View $view */
        $view = view('portal::'.$view, $data + ['principal' => $this->principal(), 'csrf' => 'tok']); // @phpstan-ignore argument.type

        return $view->render();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderGuest(string $view, array $data): string
    {
        /** @var \Illuminate\View\View $view */
        $view = view('portal::'.$view, $data); // @phpstan-ignore argument.type

        return $view->render();
    }

    private function branding(): stdClass
    {
        return $this->obj(['displayName' => 'Auto-école Soleil', 'logoUrl' => null, 'supportUrl' => null, 'allowSelfCancel' => true, 'allowPlanChange' => true]);
    }

    private function principal(): stdClass
    {
        return $this->obj(['branding' => $this->branding(), 'returnUrl' => null, 'customerRef' => 'AE0001']);
    }

    private function invoice(string $id): stdClass
    {
        return $this->obj(['id' => $id, 'number' => 'FACT-2026-000001', 'status' => 'open', 'issuedAt' => '2026-10-01', 'dueAt' => null, 'totalMinor' => 25_000, 'amountPaidMinor' => 0, 'currency' => 'XAF']);
    }

    private function home(int $open): stdClass
    {
        return $this->obj([
            'customerName' => 'Soleil',
            'dueByCurrency' => ['XAF' => 25_000 * $open],
            'nextDueAt' => null,
            'nextRenewalAt' => null,
            'paidLast12Months' => [],
            'openInvoices' => array_map(fn (int $i): stdClass => $this->invoice('i'.$i), range(1, $open)),
            'recentInvoices' => [],
            'subscriptions' => [],
            'entitlements' => null,
        ]);
    }

    private function subscription(string $id, string $plan): stdClass
    {
        return $this->obj([
            'id' => $id, 'planCode' => $plan, 'status' => 'active', 'priceMinor' => 25_000, 'currency' => 'XAF',
            'intervalUnit' => 'month', 'intervalCount' => 1, 'currentPeriodStart' => '2026-10-01', 'currentPeriodEnd' => '2026-11-01',
            'trialEndsAt' => null, 'createdAt' => '2026-01-01', 'pendingPlanVersionId' => null, 'cancelAtPeriodEnd' => false,
        ]);
    }

    private function plans(): object
    {
        $plan = $this->obj([
            'code' => 'pro', 'name' => 'Pro', 'description' => null, 'priceMinor' => 25_000, 'currency' => 'XAF',
            'intervalUnit' => 'month', 'intervalCount' => 1, 'highlights' => ['Support'], 'isCurrent' => true,
        ]);

        return new class($plan)
        {
            public bool $canSubscribe = false;

            public string $formNonce = 'n';

            public ?string $changeableSubscriptionId = null;

            /** @var list<stdClass> */
            public array $options;

            public function __construct(stdClass $plan)
            {
                $this->options = [$plan];
            }

            public function canChange(): bool
            {
                return false;
            }
        };
    }

    private function entitlement(string $type, string $key, bool $enabled, int $used = 0): stdClass
    {
        return $this->obj(['type' => $type, 'key' => $key, 'enabled' => $enabled, 'used' => $used, 'limit' => null, 'windowEnd' => null]);
    }

    /**
     * @param list<stdClass> $list
     */
    private function entitlements(array $list): stdClass
    {
        return $this->obj(['active' => true, 'entitlements' => $list]);
    }

    /**
     * The usage page receives one object (ADR-0119): the entitlements, the
     * packs offered, and the nonce of the form.
     *
     * @param list<stdClass> $list
     */
    private function usage(array $list): stdClass
    {
        return $this->obj(['entitlements' => $this->entitlements($list), 'groups' => [], 'nonce' => 'n']);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function obj(array $props): stdClass
    {
        return (object) $props;
    }
}
