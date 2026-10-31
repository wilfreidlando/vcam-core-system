<?php

declare(strict_types=1);

namespace App\Infrastructure\Guide;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Renders a flow of the guide as HTML.
 *
 * ADR-0113 — the guide is written once, in Markdown; the console shows
 * it. Two rewrites happen on the way:
 *
 * - a screenshot written `images/F01-01.png` becomes a call to the
 *   console's image route (the images live with the guide, in the
 *   repository, not in `public/` — one copy, versioned with the text);
 * - a link to another flow written `F07-emettre-une-facture.md` becomes
 *   the console's URL for that flow, with its anchor.
 *
 * Raw HTML is **stripped** (ADR-0113, decision 4): the guide is ours,
 * but the day it becomes editable in the console, a tag must not be
 * able to slip in.
 */
final class GuideRenderer
{
    public function __construct(private readonly GuideCatalog $catalog) {}

    public function render(GuideFlow $flow): string
    {
        $html = $this->converter()->convert($flow->contenu)->getContent();
        $html = $this->rewriteImages($html, $flow);
        $html = $this->rewriteNotes($html);

        return $this->rewriteLinks($html);
    }

    /**
     * The Markdown converter. `html_input: strip` keeps a raw tag out
     * of the page; `allow_unsafe_links: false` keeps a `javascript:`
     * link out of it.
     */
    private function converter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 32,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new StrikethroughExtension());

        return new MarkdownConverter($environment);
    }

    private function rewriteImages(string $html, GuideFlow $flow): string
    {
        $url = route('admin.guide.image');
        $prefix = $flow->famille.'/images/';

        return (string) preg_replace_callback(
            '/src="images\/([^"]+)"/',
            static fn (array $matches): string => sprintf(
                'src="%s?fichier=%s"',
                $url,
                rawurlencode($prefix.$matches[1]),
            ),
            $html,
        );
    }

    /**
     * A paragraph that starts with « → » is an aside: it tells the
     * reader what the software did, right after the step that caused
     * it. The Markdown stays a plain paragraph — the marker is the
     * convention, the renderer makes it an encadré.
     */
    private function rewriteNotes(string $html): string
    {
        return (string) preg_replace(
            '/<p>→ /',
            '<p class="guide-note">→ ',
            $html,
        );
    }

    private function rewriteLinks(string $html): string
    {
        $byFile = [];

        foreach ($this->catalog->flows() as $target) {
            $byFile[basename($target->chemin)] = $target;
        }

        return (string) preg_replace_callback(
            '/href="([^"]+\.md)"/',
            function (array $matches) use ($byFile): string {
                $target = $byFile[basename($matches[1])] ?? null;

                if ($target === null) {
                    return 'href="'.route('admin.guide.index').'"';
                }

                return 'href="'.route('admin.guide.'.$target->famille).'#'.$target->ancre().'"';
            },
            $html,
        );
    }
}
