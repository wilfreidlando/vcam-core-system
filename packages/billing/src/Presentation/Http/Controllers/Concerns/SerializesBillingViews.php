<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers\Concerns;

use Core\Billing\Contracts\CustomerView;
use Core\Billing\Contracts\PlanVersionView;
use Core\Billing\Contracts\PlanView;
use Core\Billing\Contracts\SubscriptionView;

/**
 * The public API's shape of the billing read models.
 *
 * The views come from `Contracts/` — the Domain models never leave the
 * module (G3c). Every key is `snake_case`, the money is an integer of
 * minor units plus its currency (G5), and nothing here is a secret: the
 * billing surface returns no token, no password and no API key (G14).
 */
trait SerializesBillingViews
{
    /**
     * ADR-0070 §Sécurité — the email and the phone leave the Core only
     * to a caller holding `customers:read`. A `customers:write`-only key
     * gets the customer without its contact details, so the write plane
     * never hands out personal data it was not entitled to read.
     *
     * @return array<string, mixed>
     */
    protected function customerView(CustomerView $view, bool $withPersonalData): array
    {
        return [
            'id' => $view->id,
            'project_id' => $view->projectId,
            'external_ref' => $view->externalRef,
            'display_name' => $view->displayName,
            ...($withPersonalData ? ['email' => $view->email, 'phone' => $view->phone] : []),
            'locale' => $view->locale,
            'metadata' => $view->metadata,
            'status' => $view->status,
            'version' => $view->version,
            'created_at' => $view->createdAt,
            'updated_at' => $view->updatedAt,
            'erased_at' => $view->erasedAt,
        ];
    }

    /**
     * A plan and its versions — the plan is stable, its versions carry
     * the terms (ADR-0071 §1).
     *
     * @return array<string, mixed>
     */
    protected function planView(PlanView $view): array
    {
        return [
            'id' => $view->id,
            'project_id' => $view->projectId,
            'code' => $view->code,
            'name' => $view->name,
            'description' => $view->description,
            'status' => $view->status,
            'visibility' => $view->visibility,
            'sort_order' => $view->sortOrder,
            'versions' => array_map($this->planVersionView(...), $view->versions),
            'created_at' => $view->createdAt,
            'updated_at' => $view->updatedAt,
        ];
    }

    /**
     * The terms of a plan version — the price is in minor units and the
     * currency is a `char(3)`, never a float (G5).
     *
     * @return array<string, mixed>
     */
    protected function planVersionView(PlanVersionView $view): array
    {
        return [
            'id' => $view->id,
            'plan_id' => $view->planId,
            'project_id' => $view->projectId,
            'version' => $view->version,
            'price_minor' => $view->priceMinor,
            'currency' => $view->currency,
            'interval_unit' => $view->intervalUnit,
            'interval_count' => $view->intervalCount,
            'trial_days' => $view->trialDays,
            'grace_days' => $view->graceDays,
            'grace_access' => $view->graceAccess,
            'features' => $view->features,
            'status' => $view->status,
            'published_at' => $view->publishedAt,
            'created_at' => $view->createdAt,
        ];
    }

    /**
     * The state of a contract, its current period and its next due date
     * (ADR-0072 §7). The price is the one the contract was signed on: a
     * later version of the same plan never touches it (ADR-0071
     * §Conséquences).
     *
     * @return array<string, mixed>
     */
    protected function subscriptionView(SubscriptionView $view): array
    {
        return [
            'id' => $view->id,
            'project_id' => $view->projectId,
            'customer_id' => $view->customerId,
            'customer_ref' => $view->customerRef,
            'plan_id' => $view->planId,
            'plan_code' => $view->planCode,
            'plan_version_id' => $view->planVersionId,
            'plan_version' => $view->planVersion,
            'status' => $view->status,
            'price_minor' => $view->priceMinor,
            'currency' => $view->currency,
            'interval_unit' => $view->intervalUnit,
            'interval_count' => $view->intervalCount,
            'anchor_at' => $view->anchorAt,
            'current_period_id' => $view->currentPeriodId,
            'current_period_start' => $view->currentPeriodStart,
            'current_period_end' => $view->currentPeriodEnd,
            'trial_ends_at' => $view->trialEndsAt,
            'cancel_at_period_end' => $view->cancelAtPeriodEnd,
            'pending_plan_version_id' => $view->pendingPlanVersionId,
            'paused_at' => $view->pausedAt,
            'canceled_at' => $view->canceledAt,
            'ended_at' => $view->endedAt,
            'has_access' => $view->hasAccess,
            'version' => $view->version,
            'created_at' => $view->createdAt,
            'updated_at' => $view->updatedAt,
        ];
    }
}
