<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Billing\Domain\Exception\InvoiceNotFound;
use Core\Billing\Domain\Exception\ManualReceiptNotFound;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Exception\PlanVersionNotFound;
use Core\Billing\Domain\Exception\SubscriptionNotFound;
use Core\Billing\Domain\Exception\TenantSchemaNotFound;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * ADR-0092 §4 — a refusal of the billing domain is the console's answer,
 * never a crash.
 *
 * The console's gestures are **mutations**: a redirect that carries the
 * refusal back to the page the operator came from keeps them in place
 * (the same idiom as the rotation of an API key, ADR-0069 §2).
 *
 * A resource that does not exist — or that belongs to another
 * organization — is answered like a missing one: the message says
 * "introuvable" and nothing else. It never answers 403, which would
 * confirm that the resource exists somewhere (ADR-0081 S-04).
 */
trait HandlesBillingRefusals
{
    private function refused(RuntimeException $exception): RedirectResponse
    {
        $message = $exception instanceof CustomerNotFound
            || $exception instanceof PlanNotFound
            || $exception instanceof PlanVersionNotFound
            || $exception instanceof SubscriptionNotFound
            || $exception instanceof InvoiceNotFound
            || $exception instanceof ManualReceiptNotFound
            || $exception instanceof TenantSchemaNotFound
            ? 'Ressource introuvable.'
            : $exception->getMessage();

        return back()->withErrors(['resource' => $message]);
    }
}
