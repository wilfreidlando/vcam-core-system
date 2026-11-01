<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * ADR-0106 — la feuille de style de la console est **compilée dans un fichier**
 * (`public/assets/admin/console.css`) au lieu d'être compilée par le navigateur
 * à chaque page (le « Play CDN »).
 *
 * Le Play CDN avait deux propriétés qu'une compilation statique n'a pas, et il
 * fallait les reproduire :
 *
 *  - il lisait le **DOM vivant** : une classe posée par le JavaScript
 *    (`rotate-90` sur le chevron des détails) n'est dans aucun gabarit, et
 *    aurait été **perdue en silence** ;
 *  - il écrivait sa sortie dans la page **à l'exécution** : 440 Ko de script
 *    sur chaque page, et un flash de contenu sans style.
 *
 * Ce test garde les deux : la feuille existe, elle est liée, elle ne ramène
 * aucun exécuteur de Tailwind, et elle contient bien les utilitaires dont la
 * console dépend — y compris ceux que seul le JavaScript pose.
 */
final class ConsoleStylesheetTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    /**
     * Les utilitaires dont la console dépend, et ce que chacun prouve de la
     * compilation. Une classe absente du fichier signifie que le compilateur
     * n'a pas lu la source dont elle vient.
     *
     * @var array<string, string>
     */
    private const REQUIRED = [
        // La coque de la console (resources/views/layouts/admin.blade.php).
        'bg-slate-900' => 'la barre latérale',
        'md:flex' => 'la barre latérale au point de rupture md — une variante',
        'antialiased' => 'le corps de page',
        'bg-gradient-to-b' => 'la page de connexion',
        // Une variante et un modificateur d'opacité.
        'hover:bg-slate-800/60' => 'les liens de la barre latérale',
        // Une valeur arbitraire (la pastille de la barre latérale).
        'min-w-[1.375rem]' => 'la pastille — une longueur arbitraire',
        // Une variante arbitraire (la jauge de quota).
        '[&::-webkit-progress-bar]:bg-slate-200' => 'la jauge — une variante arbitraire',
        // Une couleur arbitraire.
        'focus-visible:outline-[#4338ca]' => 'le halo de focus d\'un champ de formulaire',
        // Posées par console.js seulement : elles ne sont dans aucun gabarit.
        'rotate-90' => 'le chevron du détail — posé par le JavaScript',
        'opacity-50' => 'les lignes estompées — posées par le JavaScript',
    ];

    #[Test]
    public function the_compiled_stylesheet_is_served_by_the_console(): void
    {
        $path = public_path('assets/admin/console.css');

        self::assertFileExists($path);

        $size = filesize($path);
        self::assertNotFalse($size);
        self::assertGreaterThan(10_000, $size, 'the compiled stylesheet looks truncated');

        $this->signInToConsole();
        $html = (string) $this->get('/admin')->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertStringContainsString('assets/admin/console.css', $html);
    }

    #[Test]
    public function the_console_no_longer_carries_a_tailwind_runtime(): void
    {
        // Le Play CDN compilait le CSS dans le navigateur : 440 Ko de script
        // sur chaque page, et un flash de contenu sans style. Il ne doit pas
        // revenir — pas même downloadable, pour que personne ne puisse le
        // remettre à la place de la feuille compilée.
        self::assertFileDoesNotExist(public_path('assets/admin/vendor/tailwindcss-3.4.16.js'));

        $this->signInToConsole();
        $html = (string) $this->get('/admin')->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertStringNotContainsString('tailwindcss', $html);
        self::assertStringNotContainsString('cdn.tailwindcss.com', $html);
    }

    #[Test]
    public function every_utility_the_console_relies_on_is_in_the_compiled_stylesheet(): void
    {
        $css = file_get_contents(public_path('assets/admin/console.css'));
        self::assertNotFalse($css);

        foreach (self::REQUIRED as $class => $origin) {
            self::assertTrue(
                self::hasRule($css, $class),
                "`.{$class}` is missing from the compiled stylesheet ({$origin}) — run `npm run build:css`"
            );
        }
    }

    #[Test]
    public function no_page_of_the_console_loads_a_stylesheet_from_another_host(): void
    {
        $this->signInToConsole();

        foreach (['/admin', '/admin/projects', '/admin/operators', '/admin/audit-log'] as $path) {
            $html = (string) $this->get($path)->assertOk()->getContent(); // @phpstan-ignore staticMethod.dynamicCall

            preg_match_all('/<link\b[^>]*\bhref="([^"]+)"/i', $html, $links);
            foreach ($links[1] as $href) {
                self::assertDoesNotMatchRegularExpression('#^(https?:)?//(?!'.preg_quote((string) parse_url(url('/'), PHP_URL_HOST), '#').')#', $href, "{$path} loads a stylesheet from another host: {$href}");
            }
        }
    }

    /**
     * Tailwind échappe un nom de classe pour le CSS : tout caractère hors de
     * `[a-zA-Z0-9_-]` reçoit une barre oblique inverse devant lui.
     */
    private static function hasRule(string $css, string $class): bool
    {
        $escaped = (string) preg_replace('/([^a-zA-Z0-9_-])/', '\\\\$1', $class);
        $needle = '.'.$escaped;
        $offset = 0;

        while (($offset = strpos($css, $needle, $offset)) !== false) {
            $after = $css[$offset + strlen($needle)] ?? '';

            // Un caractère de classe après le nom signifierait qu'on a trouvé
            // une classe plus longue qui commence comme celle-ci.
            if ($after === '' || preg_match('/[a-zA-Z0-9_-]/', $after) !== 1) {
                return true;
            }

            $offset += strlen($needle);
        }

        return false;
    }
}
