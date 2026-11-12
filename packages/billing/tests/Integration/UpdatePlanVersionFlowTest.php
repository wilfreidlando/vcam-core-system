<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\ListPlans;
use Core\Billing\Contracts\ListPlansCommand;
use Core\Billing\Contracts\PlanVersionView;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\UpdatePlanVersion;
use Core\Billing\Contracts\UpdatePlanVersionCommand;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Exception\PlanVersionNotEditable;
use Core\Billing\Domain\Exception\PlanVersionNotFound;
use Core\Support\Access\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0071 §2 — la correction d'une version **brouillon** du catalogue.
 *
 * Le catalogue n'accepte qu'un brouillon par offre : un brouillon écrit
 * de travers n'est pas abandonné, il est **corrigé**. Sans cela
 * l'opérateur est bloqué — il ne peut ni publier ce brouillon, ni en
 * ouvrir un autre.
 *
 * Une version **publiée**, elle, reste figée : changer un prix, c'est
 * publier une nouvelle version (ADR-0071 §2). Ces deux règles sont
 * l'envers l'une de l'autre : elles sont prouvées côte à côte, contre la
 * même base, pour qu'aucune des deux ne puisse être relâchée en silence.
 *
 * Une version qui n'existe pas (offre inconnue, rang inconnu) est un
 * refus, jamais un succès silencieux : sans version à corriger, un
 * « oui » de la console ne voudrait rien dire.
 */
final class UpdatePlanVersionFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '11111111-1111-4111-8111-111111111111';

    #[Test]
    public function a_draft_version_is_corrected_and_reads_back_with_its_new_terms(): void
    {
        $this->createPlan('pro');
        $this->createDraftVersion('pro', 5_000, 'month', [
            'students' => ['type' => 'limit', 'meter' => 'students', 'limit' => 10, 'reset' => 'never', 'overage' => 'block'],
        ]);

        $updated = app(UpdatePlanVersion::class)->update(new UpdatePlanVersionCommand(
            projectId: self::PROJECT,
            planCode: 'pro',
            version: 1,
            priceMinor: 9_000,
            currency: 'XAF',
            intervalUnit: 'year',
            intervalCount: 1,
            trialDays: 0,
            graceDays: 30,
            graceAccess: false,
            features: ['api_access' => ['type' => 'boolean', 'enabled' => true]],
            actor: 'test:billing',
            correlationId: null,
        ));

        // Le prix, la période et les fonctionnalités sont ceux demandés…
        self::assertSame(9_000, $updated->priceMinor);
        self::assertSame('year', $updated->intervalUnit);
        self::assertSame(1, $updated->intervalCount);
        self::assertSame(30, $updated->graceDays);
        self::assertFalse($updated->graceAccess);

        // … et la fonctionnalité d'avant est partie : on corrige, on
        // n'ajoute pas à côté.
        self::assertArrayNotHasKey('students', $updated->features);
        self::assertSame(['type' => 'boolean', 'enabled' => true], $updated->features['api_access'] ?? null);

        // Le brouillon reste un brouillon : la correction ne publie rien.
        self::assertSame('draft', $updated->status);

        // Rendue par le catalogue — la lecture que fait la console — la
        // version porte les nouveaux termes.
        $version = $this->versionOf('pro', 1);

        self::assertSame(9_000, $version->priceMinor);
        self::assertSame('year', $version->intervalUnit);
        self::assertSame(30, $version->graceDays);
        self::assertFalse($version->graceAccess);
        self::assertArrayHasKey('api_access', $version->features);
        self::assertArrayNotHasKey('students', $version->features);

        // Sur le disque aussi : la correction a été écrite, pas seulement
        // calculée en mémoire.
        $row = DB::table('plan_versions')->where('project_id', self::PROJECT)->first();

        self::assertNotNull($row);
        self::assertEquals(9_000, $row->price_minor);
        self::assertSame('year', $row->interval_unit);
        self::assertSame('draft', $row->status);
    }

    #[Test]
    public function a_published_version_is_refused_and_its_terms_do_not_move(): void
    {
        $this->createPlan('pro');
        $this->createDraftVersion('pro', 5_000, 'month', [
            'students' => ['type' => 'limit', 'meter' => 'students', 'limit' => 10, 'reset' => 'never', 'overage' => 'block'],
        ]);

        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: self::PROJECT,
            planCode: 'pro',
            version: 1,
            actor: 'test:billing',
            correlationId: null,
        ));

        try {
            app(UpdatePlanVersion::class)->update(new UpdatePlanVersionCommand(
                projectId: self::PROJECT,
                planCode: 'pro',
                version: 1,
                priceMinor: 9_000,
                currency: 'XAF',
                intervalUnit: 'year',
                intervalCount: 1,
                trialDays: 0,
                graceDays: 30,
                graceAccess: false,
                features: ['api_access' => ['type' => 'boolean', 'enabled' => true]],
                actor: 'test:billing',
                correlationId: null,
            ));

            self::fail('Une version publiée est figée : la corriger doit être refusé (ADR-0071 §2).');
        } catch (PlanVersionNotEditable) {
            // Le refus attendu : les contrats déjà signés gardent leurs
            // conditions.
        }

        // Les termes n'ont pas bougé — ni en mémoire, ni sur le disque.
        $version = $this->versionOf('pro', 1);

        self::assertSame('published', $version->status);
        self::assertSame(5_000, $version->priceMinor);
        self::assertSame('month', $version->intervalUnit);
        self::assertSame(7, $version->graceDays);
        self::assertTrue($version->graceAccess);
        self::assertArrayHasKey('students', $version->features);
        self::assertArrayNotHasKey('api_access', $version->features);

        $row = DB::table('plan_versions')->where('project_id', self::PROJECT)->first();

        self::assertNotNull($row);
        self::assertEquals(5_000, $row->price_minor);
        self::assertSame('month', $row->interval_unit);
        self::assertSame('published', $row->status);
    }

    #[Test]
    public function an_unknown_offer_or_an_unknown_rank_is_refused_and_nothing_is_written(): void
    {
        $this->createPlan('pro');
        $this->createDraftVersion('pro', 5_000, 'month', [
            'students' => ['type' => 'limit', 'meter' => 'students', 'limit' => 10, 'reset' => 'never', 'overage' => 'block'],
        ]);

        // Une offre qui n'existe pas : rien à corriger.
        try {
            app(UpdatePlanVersion::class)->update(new UpdatePlanVersionCommand(
                projectId: self::PROJECT,
                planCode: 'fantome',
                version: 1,
                priceMinor: 9_000,
                currency: 'XAF',
                intervalUnit: 'year',
                intervalCount: 1,
                features: [],
                actor: 'test:billing',
                correlationId: null,
            ));

            self::fail('Une offre inconnue doit être refusée, pas ignorée.');
        } catch (PlanNotFound) {
            // L'offre demandée n'appartient pas à ce projet.
        }

        // Un rang inconnu : le catalogue n'a qu'une version, la première.
        try {
            app(UpdatePlanVersion::class)->update(new UpdatePlanVersionCommand(
                projectId: self::PROJECT,
                planCode: 'pro',
                version: 7,
                priceMinor: 9_000,
                currency: 'XAF',
                intervalUnit: 'year',
                intervalCount: 1,
                features: [],
                actor: 'test:billing',
                correlationId: null,
            ));

            self::fail('Un rang de version inconnu doit être refusé, pas ignoré.');
        } catch (PlanVersionNotFound) {
            // Le rang demandé n'existe pas.
        }

        // Rien n'a été écrit : le brouillon porte toujours ses termes.
        $version = $this->versionOf('pro', 1);

        self::assertSame(5_000, $version->priceMinor);
        self::assertSame('month', $version->intervalUnit);
        self::assertSame(1, DB::table('plan_versions')->count());
    }

    private function createPlan(string $code): void
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: self::PROJECT,
            code: $code,
            name: 'Plan '.$code,
            description: null,
            visibility: 'public',
            sortOrder: 0,
            actor: 'test:billing',
            correlationId: null,
        ));
    }

    /**
     * Un brouillon, prêt à être corrigé.
     *
     * @param array<string, mixed> $features
     */
    private function createDraftVersion(string $code, int $priceMinor, string $intervalUnit, array $features): void
    {
        app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: self::PROJECT,
            planCode: $code,
            priceMinor: $priceMinor,
            currency: 'XAF',
            intervalUnit: $intervalUnit,
            intervalCount: 1,
            trialDays: 14,
            graceDays: 7,
            graceAccess: true,
            features: $features,
            actor: 'test:billing',
            correlationId: null,
        ));
    }

    /**
     * La version relue par le catalogue — la même lecture que celle de la
     * console.
     */
    private function versionOf(string $code, int $number): PlanVersionView
    {
        $page = app(ListPlans::class)->list(
            new ListPlansCommand(self::PROJECT),
            AccessScope::forProject(self::PROJECT),
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
