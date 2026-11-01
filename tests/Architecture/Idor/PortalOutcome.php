<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

/**
 * What the portal must answer when the caller asks for a resource that is
 * not hers (ADR-0081 S-04: "ressource étrangère = 404, jamais 403").
 *
 * The portal has two shapes of "not found", and neither confirms that the
 * resource exists somewhere:
 *
 *  - a **read** answers 404 with the page « Introuvable »;
 *  - a **mutation** answers 422 with the page « Action impossible », whose
 *    message is the one of a resource that does not exist either (« Cet
 *    abonnement est introuvable. », « Cette offre n'est pas proposée. »).
 *
 * The generated test proves the second shape the strong way: the answer for
 * a foreign resource and the answer for an identifier nobody ever had must
 * be the same answer, byte for byte. A route that told the two apart would
 * confirm the existence of the resource.
 */
enum PortalOutcome: string
{
    /** 404 — the page « Introuvable ». */
    case NotFound = 'not-found';

    /** 422 — the page « Action impossible », the same as for a resource that does not exist. */
    case Refused = 'refused';

    /** 410 — the access link is invalid, expired or already spent. */
    case Gone = 'gone';

    public function status(): int
    {
        return match ($this) {
            self::NotFound => 404,
            self::Refused => 422,
            self::Gone => 410,
        };
    }
}
