<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

/**
 * What the console must answer for a resource outside the caller's scope
 * (ADR-0069 §2, ADR-0081 S-04: "Ressource étrangère = 404, jamais 403").
 *
 * Whatever the shape, the answer is never 403, never carries any of
 * organization B's data, and never changes it.
 */
enum ExpectedOutcome: string
{
    /** 404 — the canonical answer. */
    case NotFound = 'not-found';

    /**
     * Web console only: a redirect carrying an "introuvable" error and no
     * success flash — the answer these pages give for an identifier that
     * does not exist at all, so it confirms nothing either. Accepted as
     * equivalent to a 404 (S-04 forbids confirming existence, which this
     * does not); a 403 or a 200 never is.
     */
    case NotFoundRedirect = 'not-found-redirect';
}
