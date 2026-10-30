<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * ADR-0104 — the console runs Alpine's **CSP build** (`@alpinejs/csp`).
 *
 * Its evaluator reads a *property path* (`navOpen`, `label`, `all`) and
 * nothing else. An expression it cannot read is not an error: the
 * evaluator logs a warning in the browser's console and the behaviour
 * quietly disappears — the drawer that never opens (F-01), the dialog that
 * never traps the focus, the toggle that never fires.
 *
 * None of that breaks a PHP test. This one does: it reads the views and
 * refuses an expression the CSP build cannot evaluate, and it refuses a
 * component that `console.js` does not register.
 */
final class ConsoleCspExpressionsTest extends TestCase
{
    /**
     * A path of properties — the only shape the CSP build can read.
     */
    private const PROPERTY_PATH = '/^\$?[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_$][A-Za-z0-9_]*)*$/';

    /**
     * The directives that carry no expression: a boolean attribute, or the
     * name of an element (`x-ref`).
     *
     * @var list<string>
     */
    private const NOT_EXPRESSIONS = ['x-cloak', 'x-transition', 'x-ref'];

    #[Test]
    public function every_alpine_expression_of_the_console_is_readable_by_the_csp_build(): void
    {
        $offenders = [];

        foreach ($this->alpineAttributes() as [$file, $attribute, $value]) {
            if (in_array($attribute, self::NOT_EXPRESSIONS, true)) {
                continue;
            }

            if ($attribute === 'x-model' || $attribute === 'x-init') {
                $offenders[] = sprintf(
                    '%s: %s="%s" — le build CSP ne sait ni affecter ni exécuter une expression ; l\'état vit dans un composant de console.js.',
                    $file,
                    $attribute,
                    $value,
                );

                continue;
            }

            if ($attribute === 'x-data') {
                if (preg_match(self::PROPERTY_PATH, $value) !== 1) {
                    $offenders[] = sprintf(
                        '%s: x-data="%s" — le build CSP ne lit qu\'un chemin de propriétés : le nom d\'un composant enregistré dans console.js.',
                        $file,
                        $value,
                    );
                }

                continue;
            }

            if (preg_match(self::PROPERTY_PATH, $value) !== 1) {
                $offenders[] = sprintf(
                    '%s: %s="%s" — le build CSP ne lit qu\'un chemin de propriétés.',
                    $file,
                    $attribute,
                    $value,
                );
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Une expression Alpine illisible par le build CSP est ignorée sans erreur :\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function every_component_a_view_asks_for_is_registered(): void
    {
        $unknown = array_values(array_diff(array_keys($this->askedComponents()), $this->registeredComponents()));

        self::assertSame(
            [],
            $unknown,
            'une vue demande un composant qui n\'est pas enregistré dans public/assets/admin/console.js : '.implode(', ', $unknown),
        );
    }

    #[Test]
    public function every_registered_component_is_used_by_a_view(): void
    {
        $unused = array_values(array_diff($this->registeredComponents(), array_keys($this->askedComponents())));

        self::assertSame(
            [],
            $unused,
            'un composant est enregistré dans console.js mais aucune vue ne le demande : '.implode(', ', $unused),
        );
    }

    /**
     * The components the views ask for, and the views that ask for them.
     *
     * @return array<string, list<string>>
     */
    private function askedComponents(): array
    {
        $asked = [];

        foreach ($this->alpineAttributes() as [$file, $attribute, $value]) {
            if ($attribute !== 'x-data') {
                continue;
            }

            $asked[$value][] = $file;
        }

        return $asked;
    }

    /**
     * The components `console.js` registers.
     *
     * @return list<string>
     */
    private function registeredComponents(): array
    {
        $source = (string) file_get_contents(public_path('assets/admin/console.js'));

        preg_match_all('/Alpine\.data\(\s*\'([^\']+)\'/', $source, $matches);

        return $matches[1];
    }

    /**
     * Every Alpine directive of the console, with the file it comes from.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function alpineAttributes(): array
    {
        $found = [];

        foreach ($this->consoleViews() as $file => $source) {
            // A Blade comment is not rendered: it cannot hold a behaviour.
            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

            foreach ($this->tags($source) as $tag) {
                $isComponent = str_starts_with($tag, '<x-');

                foreach ($this->attributes($tag) as $attribute) {
                    $name = $attribute['name'];

                    if (str_starts_with($name, 'x-')) {
                        $found[] = [$file, $name, $attribute['value']];

                        continue;
                    }

                    if (str_starts_with($name, '@')) {
                        // `@checked(...)` is a Blade directive, not an Alpine
                        // handler: it carries a PHP expression, never a
                        // quoted value.
                        if (! $attribute['quoted']) {
                            continue;
                        }

                        $found[] = [$file, $name, $attribute['value']];

                        continue;
                    }

                    // `:prop` is a Blade prop on a component tag, an Alpine
                    // binding on a plain element.
                    if (! $isComponent && str_starts_with($name, ':')) {
                        $found[] = [$file, $name, $attribute['value']];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * The HTML tags of a view. A Blade component (`<x-…`) is one of them:
     * its `:props` are Blade, its `x-*` and `@*` are Alpine.
     *
     * @return list<string>
     */
    private function tags(string $source): array
    {
        preg_match_all('/<[a-zA-Z][a-zA-Z0-9:_-]*(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/', $source, $matches);

        return $matches[0];
    }

    /**
     * The attributes of a tag, with the value and whether it was quoted.
     *
     * A list and not a map: an attribute name that looks like a number
     * would be cast to an integer key by PHP.
     *
     * @return list<array{name: string, value: string, quoted: bool}>
     */
    private function attributes(string $tag): array
    {
        // Drop the tag name.
        $body = (string) preg_replace('/^<[a-zA-Z][a-zA-Z0-9:_-]*/', '', $tag);

        preg_match_all(
            '/([:@\w][-:\w.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/',
            $body,
            $matches,
            PREG_SET_ORDER,
        );

        $attributes = [];

        foreach ($matches as $match) {
            $name = $match[1];

            $quoted = str_contains($match[0], '="') || str_contains($match[0], "='");
            $double = $match[2] ?? '';
            $single = $match[3] ?? '';
            $bare = $match[4] ?? '';
            $value = $double !== '' ? $double : ($single !== '' ? $single : $bare);

            $attributes[] = ['name' => $name, 'value' => $value, 'quoted' => $quoted];
        }

        return $attributes;
    }

    /**
     * The views of the console: `resources/views/admin/**`,
     * `resources/views/components/admin/**` and the layout.
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

        $out['layouts/admin.blade.php'] = (string) file_get_contents(resource_path('views/layouts/admin.blade.php'));

        self::assertGreaterThanOrEqual(50, count($out), 'les vues de la console sont introuvables');

        return $out;
    }
}
