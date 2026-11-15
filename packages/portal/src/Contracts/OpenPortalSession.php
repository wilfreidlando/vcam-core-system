<?php

declare(strict_types=1);

namespace Core\Portal\Contracts;

/**
 * ADR-0075 §1, ADR-0125 — opening the customer's space.
 *
 * The one gesture every caller shares: an application's server (through
 * `POST /v1/portal/sessions`) and the console (through the customer's
 * sheet) both ask for a session and get a **one-time link** back.
 *
 * The link is a secret (ADR-0083 §4): it is worth one use, in the
 * window the module gives it, and it never travels in a log nor in an
 * audit entry (ADR-0081 S-07).
 *
 * A customer the project does not know raises
 * `Core\Portal\Domain\Exception\PortalCustomerNotFound`; a flow the
 * portal does not know raises
 * `Core\Portal\Domain\Exception\InvalidPortalFlow`.
 */
interface OpenPortalSession
{
    /**
     * Opens a session and answers the link that opens it.
     */
    public function open(OpenPortalSessionCommand $command): OpenedPortalSession;
}
