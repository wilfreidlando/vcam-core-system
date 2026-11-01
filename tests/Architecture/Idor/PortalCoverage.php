<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

/**
 * How a portal route is covered by the generated IDOR suite.
 *
 * The portal has two natures of routes, and the difference matters: a
 * route behind a customer session is asked for a resource of another
 * customer, a public door is asked for the very same answer as for a
 * project that does not exist, and the one-time link is asked whether it
 * can be spent twice.
 */
enum PortalCoverage: string
{
    /**
     * The session of customer A asks for a resource of customer B: the
     * answer must be the "not found" one — never a "forbidden" that would
     * confirm the resource exists (ADR-0081 S-04).
     */
    case Isolated = 'isolated';

    /**
     * The one-time link: usable once, expiring, and bound to the project
     * and the customer it was issued for.
     */
    case OneTimeLink = 'one-time-link';

    /**
     * A public door carrying an identifier: it must answer exactly like a
     * project that does not exist, so it discloses nothing.
     */
    case PublicDoor = 'public-door';
}
