<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0071 §2 — `POST /v1/plans/{code}/versions/{n}/publish`: the version
 * becomes the one new subscriptions take; the ones already signed keep
 * theirs.
 *
 * Publishing freezes the terms: the only transition a published version
 * may undergo afterwards is `published → retired`.
 */
interface PublishPlanVersion
{
    /**
     * @throws RuntimeException if the version cannot be published — an
     *                          unknown version, a version already
     *                          published or retired. The concrete
     *                          exception type lives in the Domain layer;
     *                          Contracts must not reference it (G3c).
     */
    public function publish(PublishPlanVersionCommand $command): PlanVersionView;
}
