<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Contracts\ConsumeUsage;
use Core\Billing\Contracts\ConsumeUsageCommand;
use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * Recette du 2026-10-29, vues de facturation de la console (annexes A et B) :
 * une erreur de validation est lisible ET la saisie survit, les actes irréversibles
 * demandent une confirmation, un champ validé a son champ, aucun libellé n'est orphelin,
 * et une jauge de quota ne tient pas qu'à sa couleur.
 */
final class BillingConsoleViewsTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function a_refused_plan_shows_its_message_and_keeps_what_the_operator_typed(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();
        $page = "/admin/projects/{$projectId}/plans/create";

        // Défaut #11 / #23 : un code refusé par le serveur (majuscules) ne doit pas effacer le reste.
        $this->from($page)->post("/admin/projects/{$projectId}/plans", [
            'code' => 'MAJUSCULES', 'name' => 'Offre saisie', 'description' => 'Texte libre', 'visibility' => 'private',
        ])->assertRedirect($page);

        $html = $this->get($page)->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);
        // Le message est dans la page (bloc global du layout) ET relié au champ.
        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('id="field-code-error"', $html);
        self::assertMatchesRegularExpression('/id="field-code"[^>]*aria-describedby="[^"]*field-code-error/s', $html);
        self::assertMatchesRegularExpression('/id="field-code"[^>]*aria-invalid="true"/s', $html);
        // La saisie survit : champ texte, zone de texte et liste déroulante.
        self::assertStringContainsString('value="MAJUSCULES"', $html);
        self::assertStringContainsString('value="Offre saisie"', $html);
        self::assertStringContainsString('Texte libre', $html);
        self::assertMatchesRegularExpression('/<option value="private"[^>]*selected/', $html);
    }

    #[Test]
    public function a_refused_version_keeps_the_plan_code_the_unit_and_the_grace_choice(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();
        $page = "/admin/projects/{$projectId}/plans/create";

        $this->from($page)->post("/admin/projects/{$projectId}/plans/versions", [
            'plan_code' => 'Bad Code', 'price_minor' => '1200', 'currency' => 'XAF', 'interval_unit' => 'week', 'interval_count' => '2',
            'trial_days' => '3', 'grace_days' => '5', 'grace_access' => '0',
        ])->assertRedirect($page);

        $html = $this->get($page)->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);
        // (assertSessionHasErrors consommerait le flash : le message est vérifié dans la page rendue.)
        self::assertStringContainsString('id="field-plan_code-error"', $html);
        // Défaut #23 : la clé relue est `plan_code`, pas `code`.
        self::assertStringContainsString('value="Bad Code"', $html);
        self::assertStringContainsString('value="1200"', $html);
        // Défaut #30 : l'unité choisie reste choisie (« semaine »), pas « mois » en dur.
        self::assertMatchesRegularExpression('/<option value="week"[^>]*selected/', $html);
        self::assertDoesNotMatchRegularExpression('/<option value="month"[^>]*selected/', $html);
        // Défaut #29 : le choix « accès non maintenu » survit aussi.
        self::assertDoesNotMatchRegularExpression('/<input[^>]*id="grace_access"[^>]*checked/s', $html);
    }

    #[Test]
    public function a_feature_block_that_is_incomplete_is_reported_next_to_its_field(): void
    {
        $projectId = $this->project();
        $this->plan($projectId, 'basic', withVersion: false);
        $this->signInToConsole();
        $page = "/admin/projects/{$projectId}/plans/create";

        $this->from($page)->post("/admin/projects/{$projectId}/plans/versions", [
            'plan_code' => 'basic', 'price_minor' => '100', 'currency' => 'XAF', 'interval_unit' => 'month', 'interval_count' => '1',
            'features' => [
                ['name' => 'api_access', 'type' => '', 'enabled' => '', 'meter' => '', 'limit' => '', 'reset' => '', 'overage' => '', 'threshold_bps' => ''],
            ],
        ])->assertRedirect($page);

        $html = $this->get($page)->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);
        self::assertStringContainsString('id="field-features-0-type-error"', $html);
        self::assertMatchesRegularExpression('/id="field-features-0-type"[^>]*aria-invalid="true"/s', $html);
        // La saisie survit : le nom de la fonctionnalité reste dans son champ.
        self::assertStringContainsString('value="api_access"', $html);
    }

    #[Test]
    public function the_grace_access_checkbox_exists_is_checked_by_default_and_the_choice_is_stored(): void
    {
        $projectId = $this->project();
        $this->plan($projectId, 'basic', withVersion: false);
        $this->signInToConsole();

        $html = $this->get("/admin/projects/{$projectId}/plans/create")->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);
        self::assertMatchesRegularExpression('/<input[^>]*id="grace_access"[^>]*type="checkbox"[^>]*checked|<input[^>]*type="checkbox"[^>]*id="grace_access"[^>]*checked/s', $html);
        self::assertMatchesRegularExpression('/<label[^>]*for="grace_access"/', $html);
        // Une case décochée n'envoie rien : le champ caché porte « 0 ».
        self::assertMatchesRegularExpression('/<input[^>]*type="hidden"[^>]*name="grace_access"[^>]*value="0"/', $html);

        // Ce que le navigateur envoie quand la case est décochée : « 0 » → l'accès n'est PAS maintenu.
        $this->post("/admin/projects/{$projectId}/plans/versions", [
            'plan_code' => 'basic', 'price_minor' => '100', 'currency' => 'XAF', 'interval_unit' => 'month', 'interval_count' => '1',
            'trial_days' => '0', 'grace_days' => '3', 'grace_access' => '0',
        ])->assertSessionHasNoErrors();

        self::assertFalse((bool) DB::table('plan_versions')->where('project_id', $projectId)->value('grace_access'));
    }

    #[Test]
    public function the_browser_constraints_match_the_server_rules(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();

        $plan = $this->get("/admin/projects/{$projectId}/plans/create")->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($plan);
        // #32 : `plan_code` a le motif et la longueur du serveur (`^[a-z0-9][a-z0-9_-]*$`, 64 au plus).
        self::assertMatchesRegularExpression('/<input[^>]*id="field-plan_code"[^>]*/s', $plan);
        preg_match('/<input[^>]*id="field-plan_code"[^>]*>/s', $plan, $planCode);
        self::assertStringContainsString('pattern="[a-z0-9][a-z0-9_\-]*"', $planCode[0] ?? '');
        self::assertStringContainsString('maxlength="64"', $planCode[0] ?? '');

        $customer = $this->get("/admin/projects/{$projectId}/customers/create")->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($customer);
        preg_match('/<input[^>]*id="field-external_ref"[^>]*>/s', $customer, $externalRef);
        self::assertStringContainsString('pattern="[A-Za-z0-9._:\-]+"', $externalRef[0] ?? '');
        self::assertStringContainsString(' required', $externalRef[0] ?? '');

        $invoice = $this->get("/admin/projects/{$projectId}/invoices/create")->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($invoice);
        foreach ([0, 1, 2] as $index) {
            preg_match('/<input[^>]*id="field-lines-'.$index.'-quantity"[^>]*>/s', $invoice, $quantity);
            self::assertStringContainsString(' required', $quantity[0] ?? '', "lignes[$index][quantity] doit être required");
            self::assertStringContainsString('min="1"', $quantity[0] ?? '');
        }
    }

    #[Test]
    public function the_edit_form_prefers_what_was_typed_over_the_stored_value(): void
    {
        $projectId = $this->project();
        $this->customer($projectId, 'TR0001', 'Nom stocké');
        $this->signInToConsole();
        $page = "/admin/projects/{$projectId}/customers/TR0001/edit";

        // Défaut #22 : l'opérateur corrige le nom et le courriel est refusé ; au retour, SON nom, pas le nom stocké.
        $this->from($page)->post("/admin/projects/{$projectId}/customers/TR0001", [
            'version' => '1', 'external_ref' => 'TR0001', 'display_name' => 'Nom corrigé', 'email' => 'pas-un-courriel', 'locale' => 'en',
        ])->assertRedirect($page);

        $html = $this->get($page)->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);
        self::assertStringContainsString('value="Nom corrigé"', $html);
        self::assertStringNotContainsString('value="Nom stocké"', $html);
        self::assertStringContainsString('value="en"', $html);
        self::assertStringContainsString('id="field-email-error"', $html);
    }

    #[Test]
    public function irreversible_invoice_acts_go_through_a_confirmation_dialog(): void
    {
        $projectId = $this->project();
        $this->customer($projectId, 'TR0001', 'Agence');
        $draft = $this->draftInvoice($projectId);
        $this->signInToConsole();

        // Brouillon : « Émettre » demande confirmation.
        $html = $this->get("/admin/projects/{$projectId}/invoices/{$draft}")->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);
        self::assertSame(1, substr_count($html, 'role="dialog"'));
        self::assertStringContainsString('Émettre cette facture ?', $html);
        self::assertStringContainsString('aria-haspopup="dialog"', $html);
        // Le bouton de la page n'envoie plus rien sans passer par la modale.
        self::assertStringNotContainsString('/issue', explode('role="dialog"', $html)[0]);

        // Ouverte : « Annuler la facture » et « Irrécouvrable » aussi, avec un motif obligatoire dans la modale.
        app(IssueInvoice::class)->issue(new IssueInvoiceCommand(projectId: $projectId, invoiceId: $draft, actor: 'test', correlationId: null));
        $open = $this->get("/admin/projects/{$projectId}/invoices/{$draft}")->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($open);
        self::assertSame(2, substr_count($open, 'role="dialog"'));
        self::assertStringContainsString('Annuler cette facture ?', $open);
        self::assertStringContainsString('Passer cette facture en irrécouvrable ?', $open);
        self::assertStringContainsString('id="void-reason"', $open);
        self::assertStringContainsString('id="uncollectible-reason"', $open);
        self::assertMatchesRegularExpression('/<label[^>]*for="void-reason"/', $open);
    }

    #[Test]
    public function publish_archive_suspend_erase_and_cancel_are_confirmed_too(): void
    {
        $projectId = $this->project();
        $this->plan($projectId, 'pro', publish: false);
        $this->customer($projectId, 'AE0001', 'Soleil');
        $this->signInToConsole();

        $plans = $this->get("/admin/projects/{$projectId}/plans")->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($plans);
        // « Archiver l'offre pro ? » : Blade échappe l'apostrophe de « l'offre ».
        self::assertStringContainsString('Archiver l&#039;offre pro ?', $plans);
        self::assertStringContainsString('Publier la version 1 de pro ?', $plans);
        self::assertSame(2, substr_count($plans, 'role="dialog"'));

        $customers = $this->get("/admin/projects/{$projectId}/customers")->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($customers);
        self::assertStringContainsString('Suspendre Soleil ?', $customers);
        self::assertStringContainsString('Effacer', $customers);
        self::assertSame(2, substr_count($customers, 'role="dialog"'));
        self::assertStringNotContainsString('onsubmit="return confirm', $customers);

        $this->plan($projectId, 'team');
        $subscriptionId = $this->subscribe($projectId, 'AE0001', 'team');
        $subscription = $this->get("/admin/projects/{$projectId}/subscriptions/{$subscriptionId}")->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($subscription);
        self::assertStringContainsString('Suspendre cet abonnement ?', $subscription);
        self::assertStringContainsString('Annuler cet abonnement ?', $subscription);
        self::assertSame(2, substr_count($subscription, 'role="dialog"'));
        // #31 : le n° de période à régler est pré-rempli (liste des périodes), avec une étiquette réelle.
        self::assertMatchesRegularExpression('/<label[^>]*for="field-seq"/', $subscription);
        self::assertStringContainsString('id="field-seq"', $subscription);
        if (str_contains($subscription, '<select')) {
            self::assertMatchesRegularExpression('/<select[^>]*id="field-seq"[^>]*>.*?<option value="\d+"[^>]*selected/s', $subscription);
        }
    }

    #[Test]
    public function quota_gauges_are_progress_bars_with_a_text_state_not_only_a_colour(): void
    {
        $projectId = $this->project();
        $this->plan($projectId, 'pro', limit: 10);
        $this->customer($projectId, 'AE0001', 'Soleil');
        $this->subscribe($projectId, 'AE0001', 'pro');
        app(ConsumeUsage::class)->consume(new ConsumeUsageCommand($projectId, 'AE0001', 'students', 9, 'seed-AE0001', 'api'));
        $this->signInToConsole();

        foreach (["/admin/projects/{$projectId}/usage", "/admin/projects/{$projectId}/customers/AE0001"] as $url) {
            $html = $this->get($url)->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
            self::assertIsString($html);
            self::assertStringContainsString('role="progressbar"', $html, $url);
            self::assertStringContainsString('<progress', $html, $url);
            self::assertStringContainsString('aria-valuenow="90"', $html, $url);
            // 90 % de 10 : l'état est dit en toutes lettres.
            self::assertStringContainsString('Presque atteint', $html, $url);
        }
    }

    #[Test]
    public function no_label_of_the_billing_views_is_orphaned_and_every_table_has_a_name(): void
    {
        // Lecture des sources : un <label> sans `for` est un libellé orphelin (1.3.1 / 3.3.2).
        $files = glob(resource_path('views/admin/billing').'/{,*/}*.blade.php', GLOB_BRACE);
        self::assertNotEmpty($files);
        self::assertIsArray($files);
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/<label\b[^>]*>/s', $source, $labels);
            foreach ($labels[0] as $label) {
                self::assertStringContainsString('for=', $label, basename($file).' : '.$label);
            }
            self::assertStringNotContainsString('text-slate-400', $source, basename($file));
            self::assertStringNotContainsString('onsubmit="return confirm', $source, basename($file));
        }

        // Rendu : chaque `for` pointe un `id` présent dans la page.
        $projectId = $this->project();
        $this->plan($projectId, 'pro', limit: 10);
        $this->customer($projectId, 'AE0001', 'Soleil');
        $subscriptionId = $this->subscribe($projectId, 'AE0001', 'pro');
        $draft = $this->draftInvoice($projectId, 'AE0001');
        app(IssueInvoice::class)->issue(new IssueInvoiceCommand(projectId: $projectId, invoiceId: $draft, actor: 'test', correlationId: null));
        app(ConsumeUsage::class)->consume(new ConsumeUsageCommand($projectId, 'AE0001', 'students', 3, 'seed-1', 'api'));
        $this->signInToConsole();

        $pages = [
            'plans', 'plans/create', 'customers', 'customers/create', 'customers/AE0001', 'customers/AE0001/edit',
            'invoices', 'invoices/create', "invoices/{$draft}", 'receipts', 'subscriptions', "subscriptions/{$subscriptionId}",
            'usage', 'usage?view=journal', 'ledger', 'indicators', 'portal', 'commerce-settings',
        ];
        foreach ($pages as $path) {
            $html = $this->get("/admin/projects/{$projectId}/{$path}")->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
            self::assertIsString($html);
            preg_match_all('/<label\b[^>]*>/s', $html, $labels);
            foreach ($labels[0] as $label) {
                self::assertSame(1, preg_match('/\bfor="([^"]+)"/', $label, $target), "$path : libellé sans for : $label");
                $for = $target[1] ?? '';
                self::assertStringContainsString('id="'.$for.'"', $html, "$path : for=\"{$for}\" ne pointe aucun id");
            }
            // Chaque tableau porte un nom accessible (<caption>).
            $tables = substr_count($html, '<table');
            self::assertSame($tables, substr_count($html, '<caption'), "$path : un tableau sans <caption>");
        }
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project->id;
    }

    private function customer(string $projectId, string $ref, string $name): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: $ref, displayName: $name, email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
    }

    private function plan(string $projectId, string $code, bool $withVersion = true, bool $publish = true, int $limit = 10): void
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(projectId: $projectId, code: $code, name: ucfirst($code), description: null, visibility: 'public', sortOrder: 0, actor: 'test', correlationId: null));
        if (! $withVersion) {
            return;
        }
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId, planCode: $code, priceMinor: 25_000, currency: 'XAF', intervalUnit: 'month', intervalCount: 1,
            trialDays: 0, graceDays: 7, graceAccess: true,
            features: ['students' => ['type' => 'limit', 'meter' => 'students', 'limit' => $limit, 'reset' => 'never', 'overage' => 'block']],
            actor: 'test', correlationId: null,
        ));
        if ($publish) {
            app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(projectId: $projectId, planCode: $code, version: $version->version, actor: 'test', correlationId: null));
        }
    }

    private function subscribe(string $projectId, string $ref, string $planCode): string
    {
        return app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId, customerRef: $ref, planCode: $planCode, trialDays: 14, startAt: null,
            idempotencyKey: 'sub-'.$ref.'-'.$planCode, actor: 'test', correlationId: null,
        ))->id;
    }

    private function draftInvoice(string $projectId, string $ref = 'TR0001'): string
    {
        return app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: $projectId, customerRef: $ref,
            lines: [new InvoiceLineInput(description: 'Prestation', quantity: 1, unitAmountMinor: 50_000)],
            actor: 'test', correlationId: null,
        ))->id;
    }
}
