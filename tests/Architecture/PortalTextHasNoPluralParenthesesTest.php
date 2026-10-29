<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Recette du 2026-10-29 : un client lit « illimité(s) », « 2 factures(s) » — de la rédaction de
 * développeur, pas de la langue. Le portail est la surface vue par le client final : aucun
 * « mot(s) », « mot(e) », « mot(es) » ne doit rester dans ce qu'il affiche (vues et textes produits
 * par la couche Application). On accorde, ou on reformule (« Étudiants : illimité »).
 */
final class PortalTextHasNoPluralParenthesesTest extends TestCase
{
    private const PORTAL = __DIR__.'/../../packages/portal';

    #[Test]
    public function no_text_the_customer_can_read_uses_an_optional_plural_in_parentheses(): void
    {
        $found = [];

        foreach ($this->sources() as $path => $text) {
            if (preg_match_all('/\p{L}\((?:s|e|es|x)\)/u', $text, $matches) > 0) {
                $found[] = str_replace(self::PORTAL.'/', '', $path).' : '.implode(', ', array_unique($matches[0]));
            }
        }

        self::assertSame([], $found, "Accordez ou reformulez ces libellés visibles du portail :\n  ".implode("\n  ", $found));
    }

    #[Test]
    public function the_unlimited_feature_line_reads_as_a_sentence(): void
    {
        $workspace = (string) file_get_contents(self::PORTAL.'/src/Application/UseCase/PortalWorkspace.php');

        self::assertStringContainsString("' : illimité'", $workspace);
        self::assertStringNotContainsString('illimité(s)', $workspace);
    }

    #[Test]
    public function the_scan_reads_both_the_views_and_the_application_texts(): void
    {
        $paths = array_keys($this->sources());

        self::assertNotEmpty(array_filter($paths, static fn (string $p): bool => str_ends_with($p, '.blade.php')));
        self::assertNotEmpty(array_filter($paths, static fn (string $p): bool => str_ends_with($p, 'PortalWorkspace.php')));
    }

    /**
     * @return array<string, string> path => text without comments
     */
    private function sources(): array
    {
        $out = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) realpath(self::PORTAL), RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = $file->getPathname();
            $relative = str_replace((string) realpath(self::PORTAL), '', $path);

            if (str_contains($relative, '/vendor/') || str_contains($relative, '/tests/')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            if (str_ends_with($path, '.blade.php')) {
                $out[$path] = (string) preg_replace(['/\{\{--.*?--\}\}/s', '/<!--.*?-->/s'], '', $source);
            } elseif (str_ends_with($path, '.php') && (str_contains($path, '/src/'))) {
                $code = '';
                foreach (token_get_all($source) as $token) {
                    if (is_array($token)) {
                        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                            continue;
                        }
                        $code .= $token[1];
                    } else {
                        $code .= $token;
                    }
                }
                $out[$path] = $code;
            }
        }

        return $out;
    }
}
