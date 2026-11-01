<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Portal\Application\Support\PortalPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit de sécurité 2026-10-29 (#11, garde-fou G-9) — la feuille de style
 * publique `GET /portal/theme.css`.
 *
 * L'adresse est publique : le navigateur du client la charge avant toute
 * session (la page de connexion n'a pas encore de cookie), elle ne peut donc
 * rien exiger de l'appelant. Ce qu'elle peut, c'est répondre LA MÊME chose
 * à tout ce qu'un appelant anonyme peut lui envoyer — un projet inconnu, un
 * projet qui n'a jamais configuré son portail, une valeur malformée —
 * sinon elle est un oracle d'énumération (« ce projet existe, et voici sa
 * couleur de marque »).
 *
 * Seul le `?project=` d'un projet qui a vraiment des réglages de portail
 * peut différer, et seulement par sa couleur de marque.
 */
final class PortalThemeUniformityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_project_without_a_portal_look_answers_the_same_stylesheet(): void
    {
        [$token, $projectId] = $this->project();

        $unknown = $this->get('/portal/theme.css?project='.(string) Str::uuid());
        $withoutSettings = $this->get('/portal/theme.css?project='.$projectId);
        $malformed = $this->get('/portal/theme.css?project='.str_repeat('-', 36));
        $absent = $this->get('/portal/theme.css');

        foreach ([$unknown, $withoutSettings, $malformed, $absent] as $response) {
            self::assertSame(200, $this->httpStatus($response));
            self::assertSame('text/css; charset=utf-8', $response->baseResponse->headers->get('Content-Type'));
        }

        // Le corps, octet pour octet : c'est là qu'un oracle se verrait.
        self::assertSame($this->body($unknown), $this->body($withoutSettings));
        self::assertSame($this->body($unknown), $this->body($malformed));
        self::assertSame($this->body($unknown), $this->body($absent));

        // Et les en-têtes, à ceux qui ne dépendent ni de l'horloge ni du
        // compteur de requêtes (voir stableHeaders()).
        self::assertSame($this->stableHeaders($unknown), $this->stableHeaders($withoutSettings));
        self::assertSame($this->stableHeaders($unknown), $this->stableHeaders($malformed));
        self::assertSame($this->stableHeaders($unknown), $this->stableHeaders($absent));

        // La réponse est la palette neutre : celle d'un portail sans marque.
        self::assertStringContainsString('--brand:'.PortalPalette::FALLBACK, $this->body($unknown));

        // Le projet sans réglages n'a pas de ligne `portal_settings` : il
        // doit répondre exactement comme un identifiant qui n'existe pas.
        self::assertSame(0, DB::table('portal_settings')->where('project_id', $projectId)->count());

        // Un jeton d'API ne change rien à cette porte : elle est publique.
        self::assertSame(
            $this->body($unknown),
            $this->body($this->get('/portal/theme.css?project='.$projectId, $this->auth($token))),
        );
    }

    #[Test]
    public function a_project_with_a_portal_look_answers_its_own_colour_and_nothing_else(): void
    {
        [$token, $projectId] = $this->project();
        $neutral = $this->get('/portal/theme.css?project='.$projectId);

        $this->putJson('/v1/portal/settings', ['display_name' => 'Soleil', 'primary_color' => '#0f766e'], $this->auth($token))
            ->assertOk();

        $branded = $this->get('/portal/theme.css?project='.$projectId);

        self::assertSame(200, $this->httpStatus($branded));
        self::assertStringContainsString('--brand:#0f766e', $this->body($branded));
        self::assertStringNotContainsString('--brand:'.PortalPalette::FALLBACK, $this->body($branded));

        // Rien d'autre ne bouge : même statut, mêmes en-têtes, même taille.
        self::assertSame($this->httpStatus($neutral), $this->httpStatus($branded));
        self::assertSame($this->stableHeaders($neutral), $this->stableHeaders($branded));
        self::assertSame(strlen($this->body($neutral)), strlen($this->body($branded)));
    }

    #[Test]
    public function the_public_stylesheet_keeps_its_two_guard_rails(): void
    {
        $route = Route::getRoutes()->getByName('portal.theme');
        self::assertNotNull($route);
        self::assertContains('throttle:portal-theme', $route->gatherMiddleware());

        // L'identifiant est validé avant la base : une valeur qui n'est pas un
        // UUID n'atteint jamais la colonne `uuid` (et ne plante pas).
        $malformed = $this->get('/portal/theme.css?project='.str_repeat('-', 36));
        self::assertSame(200, $this->httpStatus($malformed));
        self::assertStringContainsString('--brand:'.PortalPalette::FALLBACK, $this->body($malformed));
    }

    /**
     * The bytes of the answer.
     *
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    private function body(TestResponse $response): string
    {
        return (string) $response->baseResponse->getContent();
    }

    /**
     * The status of the answer.
     *
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    private function httpStatus(TestResponse $response): int
    {
        return $response->baseResponse->getStatusCode();
    }

    /**
     * The headers that do not depend on the request counter, on the clock or
     * on the trace of the request: every other answer must carry the same
     * ones.
     *
     * @param TestResponse<\Illuminate\Http\Response> $response
     * @return array<string, list<string|null>>
     */
    private function stableHeaders(TestResponse $response): array
    {
        $headers = $response->baseResponse->headers->all();

        foreach (array_keys($headers) as $name) {
            $lower = strtolower($name);

            // `Date` moves with the clock, `X-RateLimit-Remaining` counts the
            // requests of the minute, `Correlation-Id` follows the trace:
            // none of them says anything about the project.
            if ($lower === 'date' || $lower === 'correlation-id' || str_starts_with($lower, 'x-ratelimit-')) {
                unset($headers[$name]);
            }
        }

        return $headers;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function project(): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project;
        $issued = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project->id, ['portal:write']), actor: 'service:test', correlationId: null);

        return [$issued->plaintextToken, $project->id];
    }

    /**
     * @return array<string, string>
     */
    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }
}
