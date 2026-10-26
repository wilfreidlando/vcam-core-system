<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

/**
 * How a manifest entry is covered. A guard that claims more than it checks
 * is worse than none, so every entry says which of the two it is.
 */
enum IdorCoverage: string
{
    /**
     * Tenant isolation exercised by the generated test: an organization
     * owner of A asks for B's resource and must get "not found".
     */
    case Isolated = 'isolated';

    /**
     * Not an isolation case: the route is reserved to a platform role, and
     * an organization account is refused (403) before any resource is
     * looked up — for B's identifier exactly as for one that does not
     * exist, so the 403 confirms nothing. The generated test checks that
     * refusal; the entry carries the written reason.
     */
    case PlatformReserved = 'platform-reserved';
}
