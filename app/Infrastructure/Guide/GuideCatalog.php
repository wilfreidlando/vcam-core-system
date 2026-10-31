<?php

declare(strict_types=1);

namespace App\Infrastructure\Guide;

use RuntimeException;

/**
 * Reads the operator guide from the repository.
 *
 * ADR-0113 — the guide lives in `docs/manuel/parcours/`, in Markdown,
 * one file per flow, grouped in one folder per family. The console
 * renders it; the repository carries it. There is one source, so the
 * two cannot drift.
 *
 * The layout:
 *
 *     docs/manuel/parcours/
 *         README.md                     the index (checked by a guardrail)
 *         1-acces/
 *             F01-se-connecter.md        a flow, front matter + body
 *             images/F01-01.png         its screenshots
 *
 * The front matter is a plain `key: value` block between two `---`
 * fences. It is not YAML on purpose: the guide is read by people who
 * write Markdown, not YAML, and a parser that a reader can predict is
 * worth more than one that saves four keys.
 */
final class GuideCatalog
{
    /** The families of the guide, in reading order: slug => title. */
    private const FAMILLES = [
        'acces' => 'Se connecter et gérer les accès',
        'vendre' => 'Vendre et encaisser',
        'argent' => 'Suivre et reprendre l’argent',
        'livraisons' => 'Brancher et surveiller les livraisons',
        'piloter' => 'Extraire et piloter',
    ];

    private const FRONT_MATTER_KEYS = [
        'numero', 'titre', 'situation', 'famille', 'famille_titre', 'profil', 'temps',
    ];

    /** @var list<GuideFlow>|null */
    private ?array $flows = null;

    public function __construct(private readonly string $root) {}

    /**
     * Every flow of the guide, ordered by its number.
     *
     * @return list<GuideFlow>
     */
    public function flows(): array
    {
        if ($this->flows !== null) {
            return $this->flows;
        }

        $flows = [];

        foreach (self::FAMILLES as $famille => $familleTitre) {
            foreach ($this->filesOf($famille) as $file) {
                $flows[] = $this->read($file, $famille, $familleTitre);
            }
        }

        usort($flows, static fn (GuideFlow $a, GuideFlow $b): int => strnatcmp($a->numero, $b->numero));

        return $this->flows = $flows;
    }

    /**
     * The flows of one family, in reading order.
     *
     * @return list<GuideFlow>
     */
    public function famille(string $famille): array
    {
        return array_values(array_filter(
            $this->flows(),
            static fn (GuideFlow $flow): bool => $flow->famille === $famille,
        ));
    }

    /**
     * The title of a family, or null when the family does not exist.
     */
    public function titreFamille(string $famille): ?string
    {
        return self::FAMILLES[$famille] ?? null;
    }

    /**
     * @return array<string, string> the slugs, in reading order
     */
    public function familles(): array
    {
        return array_keys(self::FAMILLES);
    }

    /**
     * The flow a number points at.
     */
    public function parNumero(string $numero): ?GuideFlow
    {
        foreach ($this->flows() as $flow) {
            if (strcasecmp($flow->numero, $numero) === 0) {
                return $flow;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function filesOf(string $famille): array
    {
        $directory = $this->root.'/'.$famille;

        if (! is_dir($directory)) {
            return [];
        }

        $files = glob($directory.'/F*.md') ?: [];
        sort($files);

        return array_values($files);
    }

    private function read(string $file, string $famille, string $familleTitre): GuideFlow
    {
        $raw = file_get_contents($file);

        if ($raw === false) {
            throw new RuntimeException('The guide flow '.$file.' cannot be read.');
        }

        [$front, $body] = $this->split($raw, $file);

        return new GuideFlow(
            numero: $front['numero'],
            titre: $front['titre'],
            situation: $front['situation'],
            famille: $famille,
            familleTitre: $front['famille_titre'] !== '' ? $front['famille_titre'] : $familleTitre,
            profil: $front['profil'],
            temps: $front['temps'],
            chemin: substr($file, strlen($this->root) + 1),
            contenu: $body,
        );
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function split(string $raw, string $file): array
    {
        $raw = preg_replace('/\A\xEF\xBB\xBF/', '', $raw) ?? $raw;

        if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $raw, $matches) !== 1) {
            throw new RuntimeException('The guide flow '.$file.' has no front matter.');
        }

        $front = [];

        foreach (explode("\n", $matches[1]) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $position = strpos($line, ':');

            if ($position === false) {
                continue;
            }

            $front[trim(substr($line, 0, $position))] = trim(substr($line, $position + 1));
        }

        foreach (self::FRONT_MATTER_KEYS as $key) {
            if (! array_key_exists($key, $front)) {
                throw new RuntimeException(sprintf(
                    'The guide flow %s has no "%s" in its front matter.',
                    $file,
                    $key,
                ));
            }
        }

        return [$front, $matches[2]];
    }
}
