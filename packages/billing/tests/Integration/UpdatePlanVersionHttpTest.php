<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

use Core\Identity\Contracts\ApiKeyScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsWithKeys;
use Tests\TestCase;

/**
 * ADR-0071 §2 — `PUT /v1/plans/{code}/versions/{version}` : les termes
 * d'une version **brouillon**, corrigés.
 *
 * Le catalogue n'accepte qu'un brouillon par offre : un brouillon écrit
 * de travers se corrige, il ne s'abandonne pas. Une version **publiée**
 * reste figée : la corriger est un `409`, jamais une version modifiée
 * en silence.
 *
 * Les nombres viennent en chaînes aussi bien qu'en entiers : un prix
 * qui n'est pas un entier est un `422`, jamais un `500` (F-12).
 */
final class UpdatePlanVersionHttpTest extends TestCase
{
    use ProjectsWithKeys;
    use RefreshDatabase;

    #[Test]
    public function a_draft_version_is_corrected_and_stays_a_draft(): void
    {
        $headers = $this->authenticatedHeaders();
        $version = $this->draftVersion($headers);

        $response = $this->putJson("/v1/plans/pro/versions/{$version}", [
            'price_minor' => 9_000,
            'currency' => 'XAF',
            'interval_unit' => 'year',
            'interval_count' => 1,
            'trial_days' => 0,
            'grace_days' => 30,
            'grace_access' => false,
            'features' => ['api_access' => ['type' => 'boolean', 'enabled' => true]],
        ], $headers);

        $response->assertOk()
            ->assertJsonPath('price_minor', 9_000)
            ->assertJsonPath('interval_unit', 'year')
            ->assertJsonPath('grace_days', 30)
            ->assertJsonPath('grace_access', false)
            ->assertJsonPath('status', 'draft');

        // La fonctionnalité d'avant est partie : on corrige, on n'ajoute
        // pas à côté.
        $features = $response->json('features');
        self::assertIsArray($features);
        self::assertArrayHasKey('api_access', $features);
        self::assertArrayNotHasKey('students', $features);
    }

    #[Test]
    public function a_published_version_is_refused_with_a_409(): void
    {
        $headers = $this->authenticatedHeaders();
        $version = $this->draftVersion($headers);

        $this->postJson("/v1/plans/pro/versions/{$version}/publish", [], $headers)->assertOk();

        $this->putJson("/v1/plans/pro/versions/{$version}", [
            'price_minor' => 9_000,
            'currency' => 'XAF',
            'interval_unit' => 'year',
            'interval_count' => 1,
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://errors.core-system.internal/plan-version-not-editable');

        // Les termes n'ont pas bougé.
        $this->getJson('/v1/plans', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.versions.0.price_minor', 5_000)
            ->assertJsonPath('data.0.versions.0.status', 'published');
    }

    #[Test]
    public function an_unknown_version_is_a_404(): void
    {
        $headers = $this->authenticatedHeaders();
        $this->draftVersion($headers);

        $this->putJson('/v1/plans/pro/versions/7', [
            'price_minor' => 9_000,
            'currency' => 'XAF',
            'interval_unit' => 'year',
            'interval_count' => 1,
        ], $headers)->assertStatus(404);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function notAnInteger(): array
    {
        return [
            'letters' => ['abc'],
            'decimal string' => ['12.5'],
            'empty string' => [''],
            'decimal number' => [12.5],
        ];
    }

    #[Test]
    #[DataProvider('notAnInteger')]
    public function a_price_that_is_not_an_integer_is_a_422_never_a_500(mixed $price): void
    {
        $headers = $this->authenticatedHeaders();
        $version = $this->draftVersion($headers);

        $this->putJson("/v1/plans/pro/versions/{$version}", [
            'price_minor' => $price,
            'currency' => 'XAF',
            'interval_unit' => 'year',
            'interval_count' => 1,
        ], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price_minor']);
    }

    /**
     * A plan and its draft version — 5 000 XAF the month, with a limit
     * on the counter « students ».
     *
     * @param array<string, string> $headers
     */
    private function draftVersion(array $headers): int
    {
        $this->postJson('/v1/plans', ['code' => 'pro', 'name' => 'Pro'], $headers)->assertStatus(201);

        $version = $this->postJson('/v1/plans/pro/versions', [
            'price_minor' => 5_000,
            'currency' => 'XAF',
            'interval_unit' => 'month',
            'interval_count' => 1,
            'features' => ['students' => ['type' => 'limit', 'meter' => 'students', 'limit' => 10, 'reset' => 'never', 'overage' => 'block']],
        ], $headers)->assertStatus(201)->json('version');

        self::assertIsInt($version);

        return $version;
    }

    /**
     * @return array<string, string>
     */
    private function authenticatedHeaders(): array
    {
        $project = $this->makeProject('Billing');

        return $this->keyFor($project['project_id'], ApiKeyScopes::ALL);
    }
}
