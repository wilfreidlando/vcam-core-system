<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

/**
 * ADR-0119 §5 — les packs d'**un** quota, et la phrase qui dit ce qui
 * se passe.
 *
 * Le client ne connaît pas le Core : il ne voit ni compteur, ni droit,
 * ni supplément. Il voit une phrase (« Vous avez épuisé vos 500
 * messages. ») et la liste des packs que le projet propose.
 */
final readonly class PortalPackGroup
{
    /**
     * @param list<PortalPackOffer> $packs
     */
    public function __construct(
        /** Le nom lisible du quota : « messages ». */
        public string $subject,
        /** La phrase qui dit ce qui se passe. */
        public string $message,
        /** La limite effective — celle qui est appliquée. */
        public int $limit,
        /** Ce qui a déjà été consommé. */
        public int $used,
        public array $packs,
    ) {}
}
