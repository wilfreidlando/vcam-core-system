<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Billing\Domain\Exception\InvalidSubscriptionStart;
use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Requests\CreateSubscriptionRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\Middleware\RequireIdempotencyKey;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/subscriptions` — ADR-0072 §7.
 *
 * The contract a customer signs with a plan. The route carries the
 * `idempotency` middleware (G7): signing a contract twice on a replayed
 * request would be a real harm, not a duplicate row.
 */
final class CreateSubscriptionController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly CreateSubscription $createSubscription,
    ) {}

    /**
     * Create a subscription for a customer of the calling Project.
     *
     * The contract is created `trialing` when it starts with a trial,
     * `incomplete` otherwise; the first invoice is issued in the same
     * transaction (ADR-0072 §Conséquences).
     *
     * `trial_days` overrides the trial of the plan version and `start_at`
     * sets the anchor the periods are computed from (ADR-0071 §4).
     *
     * Requires the `subscriptions:write` scope and an `Idempotency-Key`
     * header — the same key guards the HTTP replay and the Core's own
     * duplicate-effect guard.
     *
     * @throws CustomerNotFound the customer doesn't exist, or belongs to another project (404)
     * @throws PlanNotFound the plan doesn't exist, or belongs to another project (404)
     * @throws InvalidSubscriptionStart the start instant is not an ISO-8601 timestamp (422)
     * @throws InvalidSubscriptionTransition the customer already has a live contract on that plan (409)
     */
    #[ScrambleResponse(status: 201, type: BillingResponses::SUBSCRIPTION, description: 'The contract, its first period and the terms it froze.')]
    public function __invoke(CreateSubscriptionRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('subscriptions:write')) {
            return ProblemDetails::forbidden('The subscriptions:write scope is required.');
        }

        $idempotencyKey = $request->header(RequireIdempotencyKey::HEADER);
        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            // Unreachable in practice — the `idempotency` middleware
            // already rejected the request otherwise — but the type
            // system doesn't know that, and failing loudly here is
            // cheaper than trusting it silently.
            throw new LogicException(self::class.' reached without a validated Idempotency-Key header.');
        }

        /** @var array{customer_ref: string, plan_code: string, trial_days?: int|null, start_at?: string|null} $data */
        $data = $request->validated();

        $view = $this->createSubscription->create(new CreateSubscriptionCommand(
            projectId: $project->projectId,
            customerRef: $data['customer_ref'],
            planCode: $data['plan_code'],
            trialDays: $data['trial_days'] ?? null,
            startAt: $data['start_at'] ?? null,
            idempotencyKey: $idempotencyKey,
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse($this->subscriptionView($view), 201);
    }
}
