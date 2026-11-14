<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Http\View\CustomerImportTemplate;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\ResolveAccessScope;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Support\Access\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * ADR-0122 §9 — le modèle du fichier à importer.
 *
 * Le lecteur refuse tout en-tête qui n'est pas exactement celui du
 * contrat : l'opérateur ne doit donc jamais avoir à deviner un nom de
 * colonne. La console lui donne le fichier — l'en-tête et une ligne
 * d'exemple — et le fichier vient de la **même source** que le lecteur :
 * le modèle et le lecteur ne peuvent pas se contredire.
 *
 * Le téléchargement est une **lecture** : le compte qui voit les clients
 * du projet (`customers:read`) peut prendre le modèle, même s'il ne peut
 * pas les écrire. L'import, qui les écrit, garde `customers:write`.
 */
final class CustomerImportTemplateConsoleTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function le_modele_telechargeable_porte_l_entete_exact_et_une_ligne_d_exemple(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();

        $response = $this->get(route('admin.projects.customers.import.template', $projectId));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="modele-import-clients.csv"');

        $content = $this->content($response);

        // The file is well formed: UTF-8, no byte-order mark (the reader
        // takes the first cell as it is), a comma as the only separator,
        // and a line ending.
        self::assertTrue(mb_check_encoding($content, 'UTF-8'), 'Le modèle doit être encodé en UTF-8.');
        self::assertStringStartsNotWith("\xEF\xBB\xBF", $content, 'Le modèle ne doit pas porter de marque d\'ordre des octets.');
        self::assertStringEndsWith("\n", $content);

        $lines = array_values(array_filter(explode("\n", $content), static fn (string $line): bool => $line !== ''));
        self::assertCount(2, $lines, 'Le modèle porte l\'en-tête et une ligne d\'exemple.');

        // The header is the contract's one, column by column.
        self::assertSame(CustomerImportTemplate::columnNames(), str_getcsv($lines[0]));
        self::assertSame(
            ['AE0001', 'Auto-école Exemple', 'contact@example.com', '+237600000000'],
            str_getcsv($lines[1]),
        );
    }

    #[Test]
    public function le_fichier_telecharge_s_importe_tel_quel(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();

        $content = $this->content($this->get(route('admin.projects.customers.import.template', $projectId)));

        $this->post(route('admin.projects.customers.import', $projectId), [
            'file' => UploadedFile::fake()->createWithContent(CustomerImportTemplate::FILENAME, $content),
        ])->assertOk();

        // The example line became a customer: the model is not a picture
        // of a file, it is a file.
        self::assertSame(1, DB::table('customers')->where('project_id', $projectId)->count());
        self::assertNotNull(
            DB::table('customers')->where('project_id', $projectId)->where('external_ref', 'AE0001')->first(),
        );
    }

    #[Test]
    public function la_page_d_import_explique_chaque_colonne_en_francais_et_propose_le_modele(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();

        $response = $this->get(route('admin.projects.customers.import', $projectId));

        $response->assertOk();

        $html = $this->content($response);

        // The button, and the route it leads to.
        self::assertStringContainsString('Télécharger le modèle', $html);
        self::assertStringContainsString(route('admin.projects.customers.import.template', $projectId), $html);

        // Each column, said in the words of the activity.
        foreach (CustomerImportTemplate::columns() as $column) {
            self::assertStringContainsString($column['label'], $html);
            self::assertStringContainsString($this->escaped($column['explanation']), $html);
        }

        self::assertStringContainsString('Référence', $html);
        self::assertStringContainsString('Nom', $html);
        self::assertStringContainsString('E-mail', $html);
        self::assertStringContainsString('Téléphone', $html);
    }

    #[Test]
    public function les_noms_techniques_des_colonnes_ne_paraisent_pas_dans_le_texte_de_la_page(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();

        $html = $this->content($this->get(route('admin.projects.customers.import', $projectId)));

        // The technical names belong to the file — the contract of the
        // format — never to a sentence the operator reads. (The two
        // unambiguous ones are checked in the rendered page; the whole
        // list is checked in the template that writes them.)
        self::assertStringNotContainsString('external_ref', $html);
        self::assertStringNotContainsString('display_name', $html);

        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/resources/views/admin/billing/customers/import.blade.php',
        );

        foreach (CustomerImportTemplate::columnNames() as $name) {
            self::assertStringNotContainsString($name, $source, sprintf(
                'Le nom technique « %s » ne doit pas figurer dans le texte que l\'opérateur lit.',
                $name,
            ));
        }
    }

    #[Test]
    public function le_telechargement_est_une_lecture_le_compte_qui_lit_les_clients_peut_prendre_le_modele(): void
    {
        $projectId = $this->signInAsOrganizationAccount(['projects:read', 'customers:read']);

        $response = $this->get(route('admin.projects.customers.import.template', $projectId));

        $response->assertOk();
        self::assertStringContainsString('external_ref,display_name,email,phone', $this->content($response));
    }

    #[Test]
    public function le_telechargement_ne_demande_pas_le_droit_d_ecrire_les_clients(): void
    {
        $projectId = $this->signInAsOrganizationAccount(['projects:read', 'customers:read']);

        // The page of the import, which writes, still refuses this
        // account: the model is a read, the import is not.
        $this->get(route('admin.projects.customers.import', $projectId))->assertForbidden();
    }

    #[Test]
    public function un_compte_qui_ne_voit_pas_les_clients_ne_telecharge_aucun_modele(): void
    {
        $projectId = $this->signInAsOrganizationAccount(['projects:read']);

        $this->get(route('admin.projects.customers.import.template', $projectId))->assertForbidden();
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project->id;
    }

    /**
     * An account of the organization, holding the given permissions — no
     * platform role, so that the permissions of the organization decide.
     *
     * Returns the id of the project the account sees.
     *
     * @param list<string> $permissions
     */
    private function signInAsOrganizationAccount(array $permissions): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project;

        $scope = new AccessScope(
            allOrganizations: false,
            organizationIds: [$organization->id],
            permissionsByOrganization: [$organization->id => $permissions],
            allProjects: false,
            projectIds: [$project->id],
            projectIdsByOrganization: [$organization->id => [$project->id]],
        );

        $this->app->bind(ResolveAccessScope::class, static fn (): ResolveAccessScope => new class($scope) implements ResolveAccessScope
        {
            public function __construct(private readonly AccessScope $scope) {}

            public function forOperator(string $operatorId): AccessScope
            {
                return $this->scope;
            }
        });

        $created = OperatorFactory::create(uniqid('op-', true).'@example.com');
        $operator = app(OperatorRepository::class)->find($created);
        self::assertInstanceOf(Operator::class, $operator);

        $this->post('/admin/login', ['email' => $operator->email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        $enroll = $this->get('/admin/mfa/enroll');
        $html = $this->content($enroll);
        if (preg_match('/name="secret"\s+value="([A-Z2-7]+)"/', $html, $secret) !== 1) {
            preg_match('/<code[^>]*>([A-Z2-7]{16,32})<\/code>/', $html, $secret);
        }
        preg_match('/name="challenge_token"\s+value="([^"]+)"/', $html, $challenge);

        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $challenge[1] ?? '',
            'code' => (new Google2FA)->getCurrentOtp($secret[1] ?? ''),
        ])->assertRedirect(route('admin.dashboard'));

        return $project->id;
    }

    /**
     * The text as the browser reads it: the page escapes what it shows.
     */
    private function escaped(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    private function content(TestResponse $response): string
    {
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $response->getContent();

        return is_string($content) ? $content : '';
    }
}
