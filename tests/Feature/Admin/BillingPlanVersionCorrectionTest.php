<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\ListPlans;
use Core\Billing\Contracts\ListPlansCommand;
use Core\Billing\Contracts\PlanVersionView;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Support\Access\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * ADR-0071 §2 — la console corrige le brouillon d'une version.
 *
 * Le catalogue n'accepte qu'un brouillon par offre : sans écran de
 * correction, un brouillon écrit de travers est une impasse — l'opérateur
 * ne peut ni le publier, ni en ouvrir un autre. La console offre donc
 * deux gestes sur la page des offres : « + Ajouter une version » (l'offre
 * reçoit une version de plus) et « Modifier » (le brouillon se corrige
 * sur place).
 *
 * Une version **publiée** reste figée : la console ne propose plus de la
 * corriger, et une correction tentée par derrière ne change rien. C'est
 * le pendant console de la règle du domaine.
 */
final class BillingPlanVersionCorrectionTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function the_offers_page_offers_both_gestures_on_the_version_of_an_offer(): void
    {
        $projectId = $this->project();
        $this->planWithDraft($projectId, 'pro');
        $this->signInToConsole();

        $html = $this->get(route('admin.projects.plans.index', $projectId))->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);

        // « + Ajouter une version » : l'offre reçoit une version de plus.
        self::assertStringContainsString('+ Ajouter une version', $html);
        self::assertStringContainsString(route('admin.projects.plans.versions.new', [$projectId, 'pro']), $html);

        // « Modifier » : le brouillon se corrige sur place.
        self::assertStringContainsString('>Modifier</a>', $html);
        self::assertStringContainsString(route('admin.projects.plans.versions.edit', [$projectId, 'pro', 1]), $html);
    }

    #[Test]
    public function the_correction_form_opens_with_the_terms_of_the_draft_already_filled_in(): void
    {
        $projectId = $this->project();
        $this->planWithDraft($projectId, 'pro');
        $this->signInToConsole();

        $html = $this->get(route('admin.projects.plans.versions.edit', [$projectId, 'pro', 1]))->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);

        // Le prix du brouillon est déjà dans le champ : l'opérateur voit
        // ce qu'il corrige avant de le corriger.
        self::assertMatchesRegularExpression('/name="price_minor"[^>]*value="5000"/s', $html);

        // La fonctionnalité aussi : son nom, son type, son compteur et sa
        // limite.
        self::assertMatchesRegularExpression('/name="features\[0\]\[name\]"[^>]*value="students"/s', $html);
        self::assertMatchesRegularExpression('/<option value="limit"[^>]*selected/s', $html);
        self::assertMatchesRegularExpression('/<option value="students"[^>]*selected/s', $html);
        self::assertMatchesRegularExpression('/name="features\[0\]\[limit\]"[^>]*value="10"/s', $html);
    }

    #[Test]
    public function posting_the_correction_changes_the_draft(): void
    {
        $projectId = $this->project();
        $this->planWithDraft($projectId, 'pro');
        $this->signInToConsole();

        $this->post(route('admin.projects.plans.versions.update', [$projectId, 'pro', 1]), [
            // Ce que le navigateur envoie : des chaînes, jamais des JSON.
            'price_minor' => '9000',
            'currency' => 'XAF',
            'interval_unit' => 'year',
            'interval_count' => '1',
            'trial_days' => '0',
            'grace_days' => '30',
            'grace_access' => '0',
            'features' => [
                ['name' => 'api_access', 'type' => 'boolean', 'enabled' => '1', 'counter' => '', 'limit' => '', 'reset' => '', 'overage' => '', 'threshold_bps' => ''],
            ],
        ])
            ->assertRedirect(route('admin.projects.plans.index', $projectId))
            ->assertSessionHas('status');

        // Le brouillon porte désormais les termes corrigés.
        $version = $this->versionOf($projectId, 'pro', 1);

        self::assertSame(9_000, $version->priceMinor);
        self::assertSame('year', $version->intervalUnit);
        self::assertSame(30, $version->graceDays);
        self::assertFalse($version->graceAccess);
        self::assertArrayHasKey('api_access', $version->features);
        self::assertArrayNotHasKey('students', $version->features);

        // Corriger ne publie pas : le brouillon reste un brouillon.
        self::assertSame('draft', $version->status);
    }

    #[Test]
    public function a_published_version_is_not_correctable_and_its_terms_do_not_move(): void
    {
        $projectId = $this->project();
        $this->planWithDraft($projectId, 'pro');
        $this->publishVersion($projectId, 'pro', 1);
        $this->signInToConsole();

        // La page des offres n'offre plus « Modifier » : une version
        // publiée est figée, on publie une nouvelle version.
        $html = $this->get(route('admin.projects.plans.index', $projectId))->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);
        self::assertStringNotContainsString('>Modifier</a>', $html);
        self::assertStringNotContainsString(route('admin.projects.plans.versions.edit', [$projectId, 'pro', 1]), $html);

        // Le formulaire de correction refuse de s'ouvrir.
        $this->get(route('admin.projects.plans.versions.edit', [$projectId, 'pro', 1]))
            ->assertRedirect(route('admin.projects.plans.index', $projectId));

        // Et une correction envoyée par derrière ne change rien.
        $this->from(route('admin.projects.plans.index', $projectId))
            ->post(route('admin.projects.plans.versions.update', [$projectId, 'pro', 1]), [
                'price_minor' => '9000',
                'currency' => 'XAF',
                'interval_unit' => 'year',
                'interval_count' => '1',
                'trial_days' => '0',
                'grace_days' => '30',
                'grace_access' => '0',
                'features' => [],
            ])
            ->assertRedirect(route('admin.projects.plans.index', $projectId))
            ->assertSessionHasErrors('resource');

        $version = $this->versionOf($projectId, 'pro', 1);

        self::assertSame('published', $version->status);
        self::assertSame(5_000, $version->priceMinor);
        self::assertSame('month', $version->intervalUnit);
        self::assertArrayHasKey('students', $version->features);
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

    private function publishVersion(string $projectId, string $code, int $version): void
    {
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: $projectId, planCode: $code, version: $version, actor: 'test', correlationId: null,
        ));
    }

    /**
     * La version relue par le catalogue — la lecture que fait la console.
     */
    private function versionOf(string $projectId, string $code, int $number): PlanVersionView
    {
        $page = app(ListPlans::class)->list(
            new ListPlansCommand($projectId),
            AccessScope::forProject($projectId),
        );

        foreach ($page->items as $plan) {
            if ($plan->code !== $code) {
                continue;
            }

            foreach ($plan->versions as $version) {
                if ($version->version === $number) {
                    return $version;
                }
            }
        }

        self::fail(sprintf('La version %d de l\'offre « %s » est introuvable.', $number, $code));
    }
}
