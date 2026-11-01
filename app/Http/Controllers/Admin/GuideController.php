<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Infrastructure\Guide\GuideCatalog;
use App\Infrastructure\Guide\GuideFlow;
use App\Infrastructure\Guide\GuideRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * The operator guide, rendered inside the console.
 *
 * ADR-0113 — the guide is written once, in Markdown, in the
 * repository (`docs/manuel/parcours/`); the console shows it. There is
 * no second copy of the text, so the screen an operator reads and the
 * file a developer edits are the same thing.
 *
 * The guide is a **document**, not a tenant resource: every operator
 * reads the same flows, and no flow mentions a customer, a project or
 * an organisation. It therefore carries no scope, and no identifier in
 * its path — the families are literal segments, and a flow is reached
 * by an anchor.
 */
final class GuideController
{
    public function __construct(
        private readonly GuideCatalog $catalog,
        private readonly GuideRenderer $renderer,
    ) {}

    /**
     * The index: « what do you want to do? »
     */
    public function index(): Response
    {
        $familles = [];

        foreach ($this->catalog->familles() as $slug) {
            $familles[] = [
                'slug' => $slug,
                'titre' => $this->catalog->titreFamille($slug),
                'parcours' => $this->catalog->famille($slug),
            ];
        }

        return response()->view('admin.guide.index', [
            'title' => 'Guide de l’opérateur',
            'familles' => $familles,
        ]);
    }

    /**
     * One family of flows: the flows are stacked on the page, each
     * reachable by its own anchor.
     */
    public function famille(string $famille): Response
    {
        $titre = $this->catalog->titreFamille($famille);

        if ($titre === null) {
            abort(404, 'Cette partie du guide n’existe pas.');
        }

        $parcours = array_map(
            fn (GuideFlow $flow): array => [
                'flow' => $flow,
                'html' => $this->renderer->render($flow),
            ],
            $this->catalog->famille($famille),
        );

        return response()->view('admin.guide.famille', [
            'title' => $titre,
            'famille' => $famille,
            'parcours' => $parcours,
        ]);
    }
}
