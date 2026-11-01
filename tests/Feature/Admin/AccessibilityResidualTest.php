<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Contracts\SuspendCustomer;
use Core\Billing\Contracts\SuspendCustomerCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * Les écarts d'accessibilité restants après la passe corrective du 2026-10-29
 * (docs/audit/annexe-accessibilite-2026-10-29) : la langue du document (A13/A14),
 * les icônes décoratives (C20), le nom accessible des tableaux (D1), la bordure
 * visible des champs (B26), la confirmation de la réactivation d'un client (R28)
 * et le type explicite des boutons du simulateur (B35).
 *
 * Deux angles : une lecture statique de toutes les vues de la console (un écart
 * de classe ou d'attribut se voit sans navigateur) et le rendu réel des pages
 * concernées.
 */
final class AccessibilityResidualTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function the_developer_documentation_declares_french(): void
    {
        // A13/A14 — `lang="en"` sur une application française (SC 3.1.2).
        foreach (['docs', 'scalar'] as $renderer) {
            $source = (string) file_get_contents(base_path('resources/views/vendor/scramble/'.$renderer.'.blade.php'));
            self::assertStringContainsString('<html lang="fr"', $source, $renderer);
            self::assertStringNotContainsString('lang="en"', $source, $renderer);
        }

        // Et la page réellement servie s'annonce comme française.
        $html = $this->get('/docs/api')->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);
        self::assertStringContainsString('<html lang="fr"', $html);
    }

    #[Test]
    public function no_console_icon_is_announced_without_a_name(): void
    {
        // C20 — une icône décorative sort de l'arbre d'accessibilité (`aria-hidden`) ;
        // une icône porteuse de sens garde `role="img"` + `aria-label` (ou un <title>).
        foreach ($this->consoleViews() as $name => $source) {
            preg_match_all('/<svg\b[^>]*\/>|<svg\b[^>]*>.*?<\/svg>/s', $source, $icons);
            foreach ($icons[0] as $icon) {
                $named = (str_contains($icon, 'role="img"') && str_contains($icon, 'aria-label='))
                    || str_contains($icon, '<title');
                if ($named) {
                    continue;
                }
                self::assertStringContainsString('aria-hidden="true"', $icon, $name.': icône décorative sans aria-hidden');
            }
        }
    }

    #[Test]
    public function every_console_table_carries_an_accessible_name(): void
    {
        // D1 — `x-admin.table` rend un <caption class="sr-only"> ; sans prop `caption`,
        // le tableau s'appelle « table » dans la navigation au clavier.
        foreach ($this->consoleViews() as $name => $source) {
            preg_match_all('/<x-admin\.table\b(.*?)(?:\/>|<\/x-admin\.table>)/s', $source, $tables);
            foreach ($tables[1] as $attributes) {
                self::assertStringContainsString('caption=', $attributes, $name.': x-admin.table sans caption');
            }
        }
    }

    #[Test]
    public function no_console_field_loses_its_visible_border(): void
    {
        // B26 — le preflight Tailwind force `border-width: 0` : un champ qui ne porte
        // que `border-slate-300` (une COULEUR) n'a AUCUNE bordure visible.
        foreach ($this->consoleViews() as $name => $source) {
            preg_match_all('/<(?:input|select|textarea)\b[^>]*>/s', $source, $controls);
            foreach ($controls[0] as $control) {
                if (preg_match('/type="(?:checkbox|radio|hidden|submit|button|file)"/', $control) === 1) {
                    continue; // la case à cocher garde son rendu natif : elle reste visible.
                }
                preg_match('/class="([^"]*)"/', $control, $class);
                $tokens = preg_split('/\s+/', trim($class[1] ?? ''));
                $tokens = $tokens === false ? [] : $tokens;
                $width = false;
                $colour = false;
                foreach ($tokens as $token) {
                    if ($token === 'border' || preg_match('/^border-(?:\d|[xstblr]|y|inline)$/', $token) === 1) {
                        $width = true;
                    }
                    if (preg_match('/(?:^|:)border-[a-z]+-\d+$/', $token) === 1) {
                        $colour = true;
                    }
                }
                if ($colour && ! $width) {
                    self::fail($name.': champ sans bordure visible : '.$control);
                }
            }
        }
    }

    #[Test]
    public function reactivating_a_customer_asks_for_confirmation(): void
    {
        // R28 — « Réactiver » rend les droits au client ET publie `customer.reactivated`
        // (webhooks aval) : comme « Suspendre », le geste passe par une confirmation.
        $projectId = $this->project();
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: 'AE0001', displayName: 'Soleil', email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
        app(SuspendCustomer::class)->suspend(new SuspendCustomerCommand(
            projectId: $projectId, externalRef: 'AE0001', actor: 'test', correlationId: null,
        ));
        $this->signInToConsole();

        $html = $this->get("/admin/projects/{$projectId}/customers")->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall
        self::assertIsString($html);
        self::assertStringContainsString('Réactiver Soleil ?', $html);
        self::assertStringContainsString('role="dialog"', $html);
        self::assertStringContainsString('aria-haspopup="dialog"', $html);
        // Le geste part de la modale, vers la même route qu'avant.
        self::assertStringContainsString(route('admin.projects.customers.reactivate.web', [$projectId, 'AE0001']), $html);
    }

    #[Test]
    public function the_simulator_buttons_submit_explicitly(): void
    {
        // B35 — un <button> sans `type` vaut « submit » par défaut ; on l'écrit.
        // I1 — la page porte aussi un <main> et un <h1>.
        $source = (string) file_get_contents(base_path('packages/payment/resources/views/simulator-checkout.blade.php'));

        preg_match_all('/<button\b[^>]*>/s', $source, $buttons);
        self::assertCount(4, $buttons[0], 'le simulateur propose quatre choix de résultat');
        foreach ($buttons[0] as $button) {
            self::assertStringContainsString('type="submit"', $button, $button);
        }

        self::assertStringContainsString('<main', $source);
        self::assertStringContainsString('<h1>', $source);
    }

    /**
     * Les vues de la console : `resources/views/admin/**` et `resources/views/components/admin/**`.
     *
     * @return array<string, string>
     */
    private function consoleViews(): array
    {
        $out = [];

        foreach ([resource_path('views/admin'), resource_path('views/components/admin')] as $root) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                    $out[ltrim(substr($file->getPathname(), strlen($root)), '/')] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        self::assertGreaterThanOrEqual(50, count($out), 'les vues de la console sont introuvables');

        return $out;
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project->id;
    }
}
