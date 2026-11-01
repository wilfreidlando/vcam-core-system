<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

/**
 * ADR-0075 §2 — what the login by code decides *before* it looks at who the
 * phone belongs to. None of these outcomes depends on the phone being known:
 * that is what keeps the answer identical for a customer and a stranger.
 */
enum LoginAdmission
{
    /** The project does not offer the login by code: the page does not exist. */
    case Disabled;

    /** The input is not a phone number. */
    case InvalidPhone;

    /** A budget (per phone, per address) is spent. */
    case Throttled;

    /** The counters cannot be read: refused, never allowed unmetered. */
    case Unavailable;

    case Accepted;
}
