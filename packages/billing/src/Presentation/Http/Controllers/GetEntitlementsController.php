<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\EntitlementView;
use Core\Billing\Contracts\GetEntitlements;
use Core\Billing\Contracts\GetEntitlementsCommand;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/customers/{externalRef}/entitlements` — ADR-0074 §4.
 *
 * "Am I paid, what may I do, how much is left" — answered from the same
 * rules the metered call applies, so the two can never disagree.
 */
final class GetEntitlementsController
{
    public function __construct(
        private readonly GetEntitlements $getEntitlements,
    ) {}

    /**
     * Read the entitlements of a customer.
     *
     * `active: false` with no entitlement means no contract grants access
     * right now (unpaid beyond the grace period, paused, suspended).
     *
     * Requires the `usage:read` scope.
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::ENTITLEMENTS, description: 'The rights of the customer and its counters of the current window.')]
    public function __invoke(string $externalRef, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('usage:read')) {
            return ProblemDetails::forbidden('The usage:read scope is required.');
        }

        $view = $this->getEntitlements->get(
            new GetEntitlementsCommand($project->projectId, $externalRef),
            AccessScope::forProject($project->projectId),
        );

        if ($view === null) {
            return ProblemDetails::make(
                'customer-not-found',
                'Customer not found',
                404,
                sprintf('No customer carries the reference "%s" in this project.', $externalRef),
            );
        }

        return new JsonResponse([
            'customer_ref' => $view->customerRef,
            'active' => $view->active,
            'plan_code' => $view->planCode,
            'subscription_id' => $view->subscriptionId,
            'entitlements' => array_map(static fn (EntitlementView $e): array => [
                'key' => $e->key,
                'type' => $e->type,
                'enabled' => $e->enabled,
                'meter' => $e->meter,
                'limit' => $e->limit,
                'used' => $e->used,
                'remaining' => $e->remaining,
                'overage' => $e->overage,
                'threshold_bps' => $e->thresholdBps,
                'window_start' => $e->windowStart,
                'window_end' => $e->windowEnd,
            ], $view->entitlements),
        ]);
    }
}
