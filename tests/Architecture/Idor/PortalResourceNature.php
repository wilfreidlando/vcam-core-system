<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

/**
 * The nature of the resource a portal route addresses — it tells the
 * scenario factory what to fabricate for the customer under test and for
 * her neighbour.
 */
enum PortalResourceNature: string
{
    /** An invoice of a customer (ADR-0073) — the document that carries the receivable. */
    case Invoice = 'invoice';

    /** A subscription contract of a customer (ADR-0072). */
    case Subscription = 'subscription';

    /** An offer of the catalogue of a project (ADR-0071) — a project resource, not a customer's. */
    case Plan = 'plan';

    /** The one-time access link a project hands to one of its customers. */
    case EntryLink = 'entry-link';

    /** A project — the identifier the public doors of the portal carry. */
    case Project = 'project';
}
