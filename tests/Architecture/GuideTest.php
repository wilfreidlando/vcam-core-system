<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * ADR-0113, decision 7 — the guide is protected by a guardrail.
 *
 * A guide that lies is worse than no guide: an operator follows a button that
 * no longer exists, and the trust in the whole document goes with it. Three
 * things are therefore proved here, on **every commit**:
 *
 * 1. every interface label the guide quotes (« Continuer », « Nouvelle
 *    facture ») is a label the product really displays — the words of the
 *    product are the haystack;
 * 2. every flow carries its front matter, and its number agrees with its
 *    file name;
 * 3. every flow is listed in the index.
 *
 * The test reads files; it needs neither the database nor the application.
 */
final class GuideTest extends TestCase
{
    private const ROOT = 'docs/manuel/parcours';

    /**
     * The front matter every flow must carry. A flow without one of them
     * cannot be listed in the index, and the index is how the reader finds it.
     */
    private const FRONT_MATTER = ['numero', 'titre', 'situation', 'famille', 'famille_titre', 'profil', 'temps'];

    /**
     * Labels the guide quotes that are **prose**, not interface strings.
     * Each one says why it cannot be found on a screen.
     */
    private const ALLOW_LIST = [
        'Ce que vous voyez' => 'the guide names its own sections',
        'Ce qui se passe' => 'the guide names its own callouts',
        'Et ensuite' => 'the guide names its own section',
        'Activer mon compte' => 'the invitation link of the login page, rendered by the layout',
    ];

    #[Test]
    public function every_flow_carries_its_front_matter(): void
    {
        $problems = [];

        foreach ($this->flowFiles() as $file) {
            $front = $this->frontMatter($file);

            foreach (self::FRONT_MATTER as $key) {
                if (($front[$key] ?? '') === '') {
                    $problems[] = $file.' has no "'.$key.'" in its front matter.';
                }
            }

            $expected = 'F'.strtoupper((string) preg_replace('/\D/', '', basename($file, '.md')));
            if ($expected !== '' && ($front['numero'] ?? '') !== $expected) {
                $problems[] = sprintf(
                    '%s says it is "%s" but its file name says "%s".',
                    $file,
                    $front['numero'] ?? '',
                    $expected,
                );
            }
        }

        self::assertSame([], $problems, "The guide has flows that cannot be listed:\n  - ".implode("\n  - ", $problems));
    }

    #[Test]
    public function every_label_the_guide_quotes_is_a_label_the_product_displays(): void
    {
        $haystack = $this->interfaceWords();
        $problems = [];

        foreach ($this->flowFiles() as $file) {
            foreach ($this->quotedLabels((string) file_get_contents($file)) as $label) {
                if (isset(self::ALLOW_LIST[$label])) {
                    continue;
                }

                if (! str_contains($haystack, $label)) {
                    $problems[] = sprintf(
                        '%s quotes « %s » — no screen of the product displays that wording any more.',
                        $file,
                        $label,
                    );
                }
            }
        }

        self::assertSame([], $problems, "The guide quotes words the product no longer says:\n  - ".implode("\n  - ", $problems));
    }

    #[Test]
    public function every_flow_is_listed_in_the_index(): void
    {
        $index = (string) file_get_contents(self::ROOT.'/README.md');
        $problems = [];

        foreach ($this->flowFiles() as $file) {
            $front = $this->frontMatter($file);
            $numero = $front['numero'] ?? '';
            $titre = $front['titre'] ?? '';

            if ($numero === '' || $titre === '') {
                continue;
            }

            if (! str_contains($index, $numero)) {
                $problems[] = $numero.' ('.$titre.') is not listed in the index.';
            }
        }

        self::assertSame([], $problems, "The index does not list every flow:\n  - ".implode("\n  - ", $problems));
    }

    /**
     * @return list<string>
     */
    private function flowFiles(): array
    {
        $files = glob(self::ROOT.'/*/F*.md') ?: [];
        sort($files);

        return array_values($files);
    }

    /**
     * @return array<string, string>
     */
    private function frontMatter(string $file): array
    {
        $raw = (string) file_get_contents($file);

        if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $raw, $matches) !== 1) {
            return [];
        }

        $front = [];

        foreach (explode("\n", $matches[1]) as $line) {
            $position = strpos($line, ':');

            if ($position === false) {
                continue;
            }

            $front[trim(substr($line, 0, $position))] = trim(substr($line, $position + 1));
        }

        return $front;
    }

    /**
     * The labels the guide quotes, with the ellipsis and the trailing colon
     * of a sentence removed: « Connexion impossible : … » is the label
     * « Connexion impossible ».
     *
     * @return list<string>
     */
    private function quotedLabels(string $markdown): array
    {
        preg_match_all('/«([^»]+)»/u', $markdown, $matches);

        $labels = [];

        foreach ($matches[1] as $label) {
            $label = trim(preg_replace('/\s*[:…]+\s*$/u', '', $label) ?? $label);

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * Every word the product displays: the console's screens, the customer's
     * portal, and the console's own labels.
     */
    private function interfaceWords(): string
    {
        $words = '';

        foreach ([
            'resources/views',
            'packages/portal/resources/views',
            'app/Http/Controllers/Admin',
            'app/Infrastructure/Guide',
        ] as $directory) {
            foreach ($this->filesOf($directory) as $file) {
                $words .= "\n".(string) file_get_contents($file);
            }
        }

        return $words;
    }

    /**
     * @return list<string>
     */
    private function filesOf(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
