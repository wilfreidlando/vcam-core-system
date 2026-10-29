<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\ApiKeyScopes;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsWithKeys;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * Audit de sécurité 2026-10-29, écart #16 : le lien signé de téléchargement d'un export n'était lié
 * ni à l'opérateur ni au projet — quiconque le possédait (historique, `Referer`, message) téléchargeait
 * l'archive complète du projet pendant 15 minutes, autant de fois qu'il voulait.
 *
 * Désormais : le lien émis par la console porte l'opérateur dans le chemin SIGNÉ et exige la session
 * de ce même opérateur, dans son périmètre ; et tout lien ne sert qu'une fois.
 */
final class ExportDownloadLinkBindingTest extends TestCase
{
    use ProjectsWithKeys;
    use RefreshDatabase;
    use SignsInToConsole;

    private string $projectId;

    #[Test]
    public function the_console_link_names_the_operator_in_its_signed_path(): void
    {
        $operatorId = $this->signInAs(OperatorRole::PlatformAdmin);
        [$exportId, $link] = $this->exportAndLink();

        self::assertStringContainsString("/exports/{$exportId}/download/{$operatorId}?", $link);
        self::assertStringContainsString('signature=', $link);
    }

    #[Test]
    public function the_operator_who_clicked_downloads_once_and_the_link_is_then_spent(): void
    {
        $this->signInAs(OperatorRole::PlatformAdmin);
        [, $link] = $this->exportAndLink();

        $this->get($link)->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->get($link)->assertForbidden();
    }

    #[Test]
    public function the_same_link_without_the_operators_console_session_does_not_deliver_the_archive(): void
    {
        $this->signInAs(OperatorRole::PlatformAdmin);
        [, $link] = $this->exportAndLink();

        // Another browser: no console session. The link leads to the sign-in page, never to the zip.
        $this->flushSession();
        $this->get($link)->assertRedirect(route('admin.login.show'));
    }

    #[Test]
    public function a_link_signed_for_another_operator_is_refused_even_though_the_signature_is_valid(): void
    {
        $this->signInAs(OperatorRole::PlatformAdmin);
        [$exportId] = $this->exportAndLink();

        $forged = URL::temporarySignedRoute(
            'exports.download.operator',
            now()->addMinutes(15),
            ['exportId' => $exportId, 'operatorId' => (string) Str::uuid()],
        );

        $this->get($forged)->assertForbidden();
    }

    #[Test]
    public function an_operator_link_cannot_be_rewritten_to_name_someone_else(): void
    {
        $operatorId = $this->signInAs(OperatorRole::PlatformAdmin);
        [, $link] = $this->exportAndLink();

        // Same signature, another operator in the path: the signature no longer matches.
        $this->get(str_replace($operatorId, (string) Str::uuid(), $link))->assertForbidden();
    }

    #[Test]
    public function an_api_link_is_authenticated_by_its_signature_and_also_works_once(): void
    {
        Http::preventStrayRequests();
        $project = $this->makeProject('Export API');
        $headers = $this->keyFor($project['project_id'], ApiKeyScopes::ALL);

        $id = self::text($this->postJson('/v1/exports', [], $headers + ['Idempotency-Key' => 'k'])->json('id'));
        $url = self::text($this->getJson("/v1/exports/{$id}", $headers)->json('download_url'));

        self::assertStringNotContainsString('/download/', $url, 'a project link names no operator');
        $this->get($url)->assertOk();
        $this->get($url)->assertForbidden();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exports');
        $project = $this->makeProject('Export Console');
        $this->projectId = $project['project_id'];
    }

    private function signInAs(OperatorRole $role): string
    {
        $email = $this->signInToConsole($role);
        $operator = app(OperatorRepository::class)->findByEmail($email);
        self::assertNotNull($operator);

        return $operator->id;
    }

    /**
     * @return array{0: string, 1: string} the export id and the signed link the console hands out
     */
    private function exportAndLink(): array
    {
        $this->post("/admin/projects/{$this->projectId}/exports")->assertRedirect();

        $exportId = DB::table('exports')->where('project_id', $this->projectId)->value('id');
        self::assertIsString($exportId);

        $response = $this->post("/admin/projects/{$this->projectId}/exports/{$exportId}/download");
        $response->assertRedirect();

        return [$exportId, (string) $response->headers->get('Location')];
    }
}
