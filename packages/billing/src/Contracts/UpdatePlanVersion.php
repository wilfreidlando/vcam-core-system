<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0071 §2 — `PUT /v1/plans/{code}/versions/{version}`: the terms of a
 * **draft** version, corrected.
 *
 * A published version is frozen — changing a price means publishing a new
 * version, never editing the old one (ADR-0071 §2). A draft is the
 * operator's working paper: it can be corrected for as long as it has not
 * been published, and since the catalogue holds only one draft per offer,
 * correcting it is the only way out of a draft that was written wrong.
 */
interface UpdatePlanVersion
{
    /**
     * @throws RuntimeException if the version cannot be changed — an unknown
     *                          plan or version, a published version (frozen
     *                          by ADR-0071 §2), or terms the catalogue does
     *                          not accept. The concrete exception type lives
     *                          in the Domain layer; Contracts must not
     *                          reference it (G3c).
     */
    public function update(UpdatePlanVersionCommand $command): PlanVersionView;
}
