<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Domain\Exception\PlanVersionConflict;
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
 * ADR-0071 §2 — la console refuse en français, et elle refuse la bonne chose.
 *
 * Trois refus que l'opérateur rencontre sur les offres :
 *
 * - une offre qui a **déjà** une version en préparation : le catalogue
 *   n'accepte qu'un brouillon par offre. La phrase dit « corrigez-la »,
 *   et elle ne parle pas d'une version « publiée et figée » d'une
 *   version qui n'a jamais été publiée ;
 * - une offre qui n'existe pas : la console revient à la liste des
 *   offres, elle ne rend pas une page JSON ;
 * - une correction : le journal garde les valeurs, pour que l'on puisse
 *   dire « le prix était 5 000 avant ».
 *
 * Le refus du domaine porte la cause ; sa traduction en français est
 * l'affaire de la console (`HandlesBillingRefusals`).
 */
final class BillingPlanVersionRefusalsTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function adding_a_version_to_an_offer_that_already_holds_a_draft_is_refused_in_plain_french(): void
    {
        $projectId = $this->project();
        $this->planWithDraft($projectId, 'pro');
        $this->signInToConsole();

        $response = $this->post(route('admin.projects.plans.versions.store', [$projectId, 'pro']), [
            'price_minor' => '9000',
            'currency' => 'XAF',
            'interval_unit' => 'year',
            'interval_count' => '1',
            'trial_days' => '0',
            'grace_days' => '30',
            'grace_access' => '0',
            'features' => [],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('resource', function (string $message): bool {
            // La phrase dit ce qu'il faut faire…
            self::assertStringContainsString('version en préparation', $message);
            self::assertStringContainsString('corrigez-la', $message);

            // … elle est en français, sans référence interne…
            self::assertStringNotContainsString('ADR-', $message);
            self::assertStringNotContainsString('immutable', $message);
            self::assertStringNotContainsString('published', $message);

            return true;
        });

        // Rien n'a été écrit : l'offre garde son seul brouillon.
        self::assertSame(1, DB::table('plan_versions')->where('project_id', $projectId)->count());
    }

    #[Test]
    public function the_domain_names_the_two_catalogue_rules_apart(): void
    {
        $projectId = $this->project();
        $this->planWithDraft($projectId, 'pro');

        // Le catalogue n'accepte qu'un brouillon par offre : la cause est
        // nommée, et elle ne dit pas « publiée et figée ».
        try {
            app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
                projectId: $projectId,
                planCode: 'pro',
                priceMinor: 9_000,
                currency: 'XAF',
                intervalUnit: 'year',
                intervalCount: 1,
                trialDays: 0,
                graceDays: 30,
                graceAccess: false,
                features: [],
                actor: 'test',
                correlationId: null,
            ));

            self::fail('Une offre qui a déjà une version en préparation n\'en accepte pas une seconde.');
        } catch (PlanVersionConflict $conflict) {
            self::assertSame(PlanVersionConflict::DRAFT_EXISTS, $conflict->reason);
            self::assertSame(1, $conflict->version);
        }
    }

    #[Test]
    public function an_unknown_offer_leads_back_to_the_offers_page_and_not_to_a_json_page(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();

        $this->get(route('admin.projects.plans.versions.new', [$projectId, 'fantome']))
            ->assertRedirect(route('admin.projects.plans.index', $projectId));

        $this->get(route('admin.projects.plans.versions.edit', [$projectId, 'fantome', 1]))
            ->assertRedirect(route('admin.projects.plans.index', $projectId));
    }

    #[Test]
    public function the_correction_is_audited_with_the_price_it_had(): void
    {
        $projectId = $this->project();
        $this->planWithDraft($projectId, 'pro');
        $this->signInToConsole();

        $this->post(route('admin.projects.plans.versions.update', [$projectId, 'pro', 1]), [
            'price_minor' => '9000',
            'currency' => 'XAF',
            'interval_unit' => 'year',
            'interval_count' => '1',
            'trial_days' => '0',
            'grace_days' => '30',
            'grace_access' => '0',
            'features' => [],
        ])->assertRedirect(route('admin.projects.plans.index', $projectId));

        $entry = DB::table('audit_log')->where('action', 'billing.plan_version.updated')->first();

        self::assertNotNull($entry);

        $before = $this->decoded($entry->before);
        $after = $this->decoded($entry->after);

        // Le journal dit ce que le prix était, et ce qu'il devient.
        self::assertIsArray($before);
        self::assertIsArray($after);
        self::assertSame(5_000, $before['price_minor'] ?? null);
        self::assertSame('month', $before['interval_unit'] ?? null);
        self::assertSame(9_000, $after['price_minor'] ?? null);
        self::assertSame('year', $after['interval_unit'] ?? null);
    }

    /**
     * A JSON column of the audit trail, decoded.
     *
     * @return array<mixed, mixed>|null
     */
    private function decoded(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project->id;
    }

    /**
     * Une offre et son brouillon : 5 000 XAF le mois, avec une limite
     * d'usage sur le compteur « students ».
     */
    private function planWithDraft(string $projectId, string $code): void
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: $projectId, code: $code, name: ucfirst($code), description: null,
            visibility: 'public', sortOrder: 0, actor: 'test', correlationId: null,
        ));

        app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId, planCode: $code, priceMinor: 5_000, currency: 'XAF',
            intervalUnit: 'month', intervalCount: 1, trialDays: 14, graceDays: 7, graceAccess: true,
            features: ['students' => ['type' => 'limit', 'meter' => 'students', 'limit' => 10, 'reset' => 'never', 'overage' => 'block']],
            actor: 'test', correlationId: null,
        ));
    }
}
