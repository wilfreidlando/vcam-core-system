<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OperatorFactory;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * Recette du 2026-10-29 — gabarits d'identité de la console (connexion en deux temps,
 * invitation, organisations, opérateurs, membres, projets) : écarts A10-A12, V-04,
 * B2/B10/B11/B26/B30, C1/C2/C10-C15, D5/D6, H1/H3/H4.
 *
 * Les tests lisent le HTML que le navigateur reçoit : aucune de ces erreurs n'était
 * visible d'un test qui s'arrête au code HTTP.
 */
final class IdentityConsoleViewsTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function the_mfa_enrolment_page_has_the_page_title_as_its_only_h1_and_stacks_its_columns(): void
    {
        $email = uniqid('op-', true).'@example.com';
        OperatorFactory::create($email, \Core\Identity\OperatorRole::PlatformAdmin);
        $this->post('/admin/login', ['email' => $email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        $html = $this->page('/admin/mfa/enroll');

        // A10 — le titre de page est le <h1> ; la marque est un <p>.
        self::assertSame(1, preg_match_all('/<h1\b/', $html), 'one <h1> per page');
        self::assertMatchesRegularExpression('/<h1[^>]*>\s*Configurer un authentificateur\s*<\/h1>/', $html);
        self::assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*Enterprise Core/', $html);
        self::assertStringNotContainsString('<h2', $html);

        // V-04 — QR et secret s'empilent sous 640 px.
        self::assertStringContainsString('grid grid-cols-1 sm:grid-cols-2', $html);
    }

    #[Test]
    public function the_invitation_page_has_the_page_title_as_its_only_h1_and_links_the_error_to_its_field(): void
    {
        $html = $this->page('/admin/invitation');

        // A12
        self::assertSame(1, preg_match_all('/<h1\b/', $html));
        self::assertMatchesRegularExpression('/<h1[^>]*>\s*Activer mon compte\s*<\/h1>/', $html);
        self::assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*Enterprise Core/', $html);

        // Un jeton absent : le bandeau d'erreurs est une région `alert` et le champ désigne son message.
        $response = $this->from('/admin/invitation')->followingRedirects()->post('/admin/invitation', [
            'token' => '', 'password' => 'x', 'password_confirmation' => 'y',
        ]);
        /** @var string|false $failed */
        $failed = $response->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($failed);
        self::assertStringContainsString('role="alert"', $failed);
        self::assertStringContainsString('aria-invalid="true"', $failed);
        self::assertMatchesRegularExpression('/aria-describedby="[^"]*(token|password)-error/', $failed);
    }

    #[Test]
    public function the_operator_list_opens_its_revocation_in_a_real_dialog(): void
    {
        $this->signInToConsole();
        OperatorFactory::create(uniqid('other-', true).'@example.com', \Core\Identity\OperatorRole::PlatformAdmin);

        $html = $this->page('/admin/operators');

        // C1/C2/C14/C15
        self::assertStringContainsString('role="dialog"', $html);
        self::assertStringContainsString('aria-modal="true"', $html);
        self::assertStringContainsString('aria-labelledby=', $html);
        self::assertStringContainsString('aria-haspopup="dialog"', $html);
        self::assertStringNotContainsString('confirmRevoke', $html, 'the hand-made modal is gone');

        // D5/D6 — tableau nommé, colonne d'actions annoncée, <tbody> explicite.
        self::assertStringContainsString('<caption class="sr-only">', $html);
        self::assertStringContainsString('aria-label="Actions"', $html);
        self::assertStringContainsString('<tbody', $html);
    }

    #[Test]
    public function the_member_list_has_a_labelled_role_selector_applied_by_a_button_and_a_dialog_for_revocation(): void
    {
        $this->signInToConsole();
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org membres'), actor: 'service:test', correlationId: null);
        $memberId = OperatorFactory::create(uniqid('member-', true).'@example.com', null);
        app(OrganizationMembershipRepository::class)->save(new OrganizationMembership(
            id: (string) Str::uuid(),
            organizationId: $organization->id,
            operatorId: $memberId,
            role: OrganizationRole::Viewer,
            createdAt: new DateTimeImmutable,
        ));

        $html = $this->page("/admin/organizations/{$organization->id}/members");

        // B10 — le sélecteur de rôle a une étiquette reliée par `for`.
        self::assertStringContainsString('<label for="role-'.$memberId.'"', $html);
        self::assertStringContainsString('id="role-'.$memberId.'"', $html);

        // B11 — plus de soumission à la volée (WCAG 3.2.2) : un bouton « Appliquer ».
        self::assertStringNotContainsString('onchange=', $html);
        self::assertStringContainsString('Appliquer', $html);

        // C10-C12
        self::assertStringContainsString('role="dialog"', $html);
        self::assertStringContainsString('aria-modal="true"', $html);
        self::assertStringContainsString('aria-haspopup="dialog"', $html);
        self::assertStringNotContainsString('confirmRevoke', $html);
    }

    #[Test]
    public function every_label_of_the_identity_forms_is_bound_to_its_control(): void
    {
        $this->signInToConsole();
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org formulaires'), actor: 'service:test', correlationId: null);
        $projectId = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet formulaires'), actor: 'service:test', correlationId: null)->project->id;
        $operatorId = OperatorFactory::create(uniqid('edit-', true).'@example.com', \Core\Identity\OperatorRole::PlatformAdmin);

        $paths = [
            '/admin/organizations/create',
            "/admin/organizations/{$organization->id}/edit",
            '/admin/operators/create',
            "/admin/operators/{$operatorId}/edit",
            "/admin/organizations/{$organization->id}/members/invite",
            '/admin/projects/create',
            "/admin/projects/{$projectId}/edit",
            '/admin/projects',
        ];

        foreach ($paths as $path) {
            $html = $this->page($path);

            // B2 — aucun <label> sans `for`.
            self::assertDoesNotMatchRegularExpression('/<label\b(?![^>]*\bfor=)/', $html, "label without `for` on {$path}");

            // B26 — chaque champ saisi porte une bordure visible (Tailwind preflight : border-width:0).
            preg_match_all('/<(?:input|select|textarea)\b(?![^>]*type="(?:hidden|submit|checkbox|radio)")[^>]*>/', $html, $controls);
            foreach ($controls[0] as $control) {
                if (str_contains($control, 'name="')) {
                    self::assertMatchesRegularExpression('/class="[^"]*\bborder\b/', $control, "control without border on {$path}: {$control}");
                }
            }
        }

        // B30 — l'email d'un opérateur est un identifiant : jamais `autocomplete="off"`.
        $html = $this->page('/admin/operators/create');
        self::assertSame(1, preg_match('/<input\b[^>]*name="email"[^>]*>/', $html, $emailInput));
        $emailTag = $emailInput[0] ?? '';
        self::assertStringContainsString('autocomplete="email"', $emailTag);
        self::assertStringNotContainsString('autocomplete="off"', $emailTag);
    }

    #[Test]
    public function a_project_page_still_offers_the_links_the_operator_may_use(): void
    {
        $this->signInToConsole();
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org projet'), actor: 'service:test', correlationId: null);
        $projectId = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet liens'), actor: 'service:test', correlationId: null)->project->id;

        $html = $this->page("/admin/projects/{$projectId}");

        self::assertStringContainsString('Paiements de ce projet', $html);
        self::assertStringContainsString('Clés API', $html);
        self::assertStringContainsString('Réglages du commerce', $html);
    }

    private function page(string $path): string
    {
        $content = $this->get($path)->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        return is_string($content) ? $content : '';
    }
}
