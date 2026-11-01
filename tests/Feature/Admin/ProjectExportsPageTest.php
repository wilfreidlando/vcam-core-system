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
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * ADR-0088 §3 — « Export des données » on the project page of the console:
 * the request button, the list of past exports, the signed link made at the
 * click. A form posts strings and a CSRF token — nothing else.
 */
final class ProjectExportsPageTest extends TestCase
{
    use RefreshDatabase;

    private string $projectId;

    #[Test]
    public function an_operator_requests_an_export_sees_it_listed_and_downloads_it_through_a_signed_link(): void
    {
        $operatorId = $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $this->get("/admin/projects/{$this->projectId}")->assertOk()->assertSee('Export des données');
        $this->get("/admin/projects/{$this->projectId}/exports")
            ->assertOk()
            ->assertSee('Demander un export')
            ->assertSee('Aucun export pour ce projet.')
            // The breadcrumb: Projets › the project › Export des données.
            ->assertSeeInOrder(['Projets', 'Projet', 'Export des données']);

        // The button posts the form: a CSRF token and nothing else.
        $this->post("/admin/projects/{$this->projectId}/exports")
            ->assertRedirect("/admin/projects/{$this->projectId}/exports")
            ->assertSessionHas('status');

        $export = DB::table('exports')->where('project_id', $this->projectId)->first();
        self::assertNotNull($export);
        $exportId = is_string($export->id) ? $export->id : '';
        self::assertSame('operator:'.$operatorId, $export->requested_by);
        // The queue is synchronous in tests: the export is already built.
        self::assertSame('done', $export->status);

        $audit = DB::table('audit_log')->where('resource', "export:{$exportId}")->where('action', 'export.requested')->first();
        self::assertNotNull($audit);
        self::assertSame('operator:'.$operatorId, $audit->actor);

        $this->get("/admin/projects/{$this->projectId}/exports")->assertOk()->assertSee('Prêt')->assertSee('Télécharger');

        // The link is generated at the click: signed, fifteen minutes.
        $response = $this->post("/admin/projects/{$this->projectId}/exports/{$exportId}/download");
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        self::assertStringContainsString("/exports/{$exportId}/download", $location);
        self::assertStringContainsString('signature=', $location);
        self::assertStringContainsString('expires=', $location);

        $this->get($location)->assertOk()->assertHeader('Content-Type', 'application/zip');
    }

    #[Test]
    public function a_read_only_role_neither_sees_nor_requests_an_export(): void
    {
        $this->loginAndEnrollMfa(OperatorRole::ReadOnlyAuditor);

        $this->get("/admin/projects/{$this->projectId}/exports")->assertForbidden();
        $this->post("/admin/projects/{$this->projectId}/exports")->assertForbidden();
        self::assertSame(0, DB::table('exports')->count());
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exports');
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $this->projectId = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project->id;
    }

    private function loginAndEnrollMfa(OperatorRole $role): string
    {
        $created = OperatorFactory::create(uniqid('op-', true).'@example.com', $role);
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

        return $created;
    }
}
