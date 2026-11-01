<?php

declare(strict_types=1);

namespace App\Infrastructure\Guide;

/**
 * One flow of the operator guide: a file, its front matter, and the
 * anchor it is reachable at.
 *
 * ADR-0113 — the guide is written once, in Markdown, under
 * `docs/manuel/parcours/`. The console renders it; the repository
 * carries it. A flow is therefore a *document*, not a tenant resource:
 * it belongs to nobody and leaks nothing (ADR-0113, decision 2).
 */
final readonly class GuideFlow
{
    /**
     * @param string $numero      'F01' — the number the guide quotes everywhere.
     * @param string $titre       'Se connecter et confirmer mon identité'.
     * @param string $situation   'Ouvrir la console le matin' — what the reader came to do.
     * @param string $famille     'acces' — the slug of the family it belongs to.
     * @param string $familleTitre 'Se connecter et gérer les accès'.
     * @param string $profil      'Opérateur' — who the flow is written for.
     * @param string $temps       '2 minutes'.
     * @param string $chemin      The path of the Markdown file, relative to the guide root.
     * @param string $contenu     The Markdown body, front matter excluded.
     */
    public function __construct(
        public string $numero,
        public string $titre,
        public string $situation,
        public string $famille,
        public string $familleTitre,
        public string $profil,
        public string $temps,
        public string $chemin,
        public string $contenu,
    ) {}

    /**
     * The anchor the flow is reachable at. Deliberately trivial: the
     * number, lowercased. Computing a slug from the title would mean
     * reproducing GitHub's algorithm and hoping the console and the
     * repository agree on it; the number cannot drift.
     */
    public function ancre(): string
    {
        return mb_strtolower($this->numero);
    }
}
