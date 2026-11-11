<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * Found by the browser end-to-end session: the « new plan » page answered 500
 * (its version form built a route with an empty plan code). The page now
 * renders, and the whole path an operator follows from it works.
 */
final class BillingPlansPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_operator_creates_a_plan_adds_a_version_from_the_same_page_and_publishes_it(): void
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $projectId = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project->id;
        $this->loginAndEnrollMfa();

        $this->get("/admin/projects/{$projectId}/plans/create")->assertOk()->assertSee('Ajouter une version');

        $this->post("/admin/projects/{$projectId}/plans", ['code' => 'pro', 'name' => 'Pro', 'visibility' => 'public'])
            ->assertRedirect("/admin/projects/{$projectId}/plans");

        $this->post("/admin/projects/{$projectId}/plans/versions", [
            // Strings, as a browser sends them: the command behind is strictly typed.
            'plan_code' => 'pro', 'price_minor' => '25000', 'currency' => 'xaf', 'interval_unit' => 'month', 'interval_count' => '1',
            'trial_days' => '14', 'grace_days' => '7',
            // Les fonctionnalités se déclarent bloc par bloc, jamais en JSON.
            'features' => [
                ['name' => 'students', 'type' => 'limit', 'enabled' => '', 'meter' => 'students', 'limit' => '10', 'reset' => 'never', 'overage' => 'block', 'threshold_bps' => ''],
            ],
        ])->assertRedirect("/admin/projects/{$projectId}/plans");

        self::assertEquals(25_000, DB::table('plan_versions')->where('project_id', $projectId)->value('price_minor'));

        $this->post("/admin/projects/{$projectId}/plans/pro/versions/1/publish")->assertRedirect();
        self::assertSame('published', DB::table('plan_versions')->where('project_id', $projectId)->value('status'));

        // A plan code that is not a code is refused with a message, nothing is written.
        $this->post("/admin/projects/{$projectId}/plans/versions", ['plan_code' => 'Bad Code', 'price_minor' => 1, 'currency' => 'XAF', 'interval_unit' => 'month', 'interval_count' => 1])
            ->assertSessionHasErrors('plan_code');
    }

    #[Test]
    public function a_manual_invoice_is_created_from_one_filled_row_with_the_rest_of_the_form_empty(): void
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $projectId = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project->id;
        app(\Core\Billing\Contracts\UpsertCustomer::class)->upsert(new \Core\Billing\Contracts\UpsertCustomerCommand(
            projectId: $projectId, externalRef: 'TR0001', displayName: 'Agence', email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
        $this->loginAndEnrollMfa();

        // What a browser sends: every field is a string, the unused rows arrive empty (quantity keeps its default).
        $this->post("/admin/projects/{$projectId}/invoices", [
            'customer_ref' => 'TR0001', 'currency' => 'XAF', 'tax_rate_bps' => '', 'due_at' => '',
            'lines' => [
                ['description' => 'Abonnement Pro', 'quantity' => '1', 'unit_amount_minor' => '25000', 'tax_rate_bps' => ''],
                ['description' => '', 'quantity' => '1', 'unit_amount_minor' => '', 'tax_rate_bps' => ''],
                ['description' => '', 'quantity' => '1', 'unit_amount_minor' => '', 'tax_rate_bps' => ''],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        self::assertSame(1, DB::table('invoice_lines')->count());
        self::assertEquals(25_000, DB::table('invoices')->where('project_id', $projectId)->value('total_minor'));
    }

    private function loginAndEnrollMfa(): void
    {
        $created = OperatorFactory::create(uniqid('op-', true).'@example.com', OperatorRole::PlatformAdmin);
        $operator = app(OperatorRepository::class)->find($created);
        assert($operator instanceof Operator);

        $this->post('/admin/login', ['email' => $operator->email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $this->get('/admin/mfa/enroll')->getContent();
        $html = is_string($content) ? $content : '';
        if (preg_match('/name="secret"\s+value="([A-Z2-7]+)"/', $html, $secret) !== 1) {
            preg_match('/<code[^>]*>([A-Z2-7]{16,32})<\/code>/', $html, $secret);
        }
        preg_match('/name="challenge_token"\s+value="([^"]+)"/', $html, $challenge);

        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $challenge[1] ?? '',
            'code' => (new Google2FA)->getCurrentOtp($secret[1] ?? ''),
        ])->assertRedirect(route('admin.dashboard'));
    }
}
