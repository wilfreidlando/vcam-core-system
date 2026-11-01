<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\ConsumeUsage;
use Core\Billing\Contracts\ConsumeUsageCommand;
use Core\Billing\Contracts\UsageDecision;
use Core\Billing\Presentation\Http\Requests\ConsumeUsageRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/usage` — ADR-0074 §4.
 *
 * The project states an usage only it can observe in its own domain (a
 * student enrolled). The Core guarantees atomicity, idempotency and the
 * refusal beyond the plan: the check and the write are one statement.
 */
final class ConsumeUsageController
{
    public function __construct(
        private readonly ConsumeUsage $consumeUsage,
    ) {}

    /**
     * Spend units of a meter.
     *
     * `201` when the unit is counted, `200` when the same
     * `idempotency_key` was already counted (nothing is counted twice),
     * `429 quota-exceeded` when the plan's limit would be crossed — a
     * refused call writes nothing and may be replayed with the same key.
     *
     * Requires the `usage:write` scope.
     */
    #[ScrambleResponse(status: 201, type: BillingResponses::USAGE_DECISION, description: 'The unit is counted.')]
    #[ScrambleResponse(status: 200, type: BillingResponses::USAGE_DECISION, description: 'The key was already counted; the decision is replayed.')]
    public function __invoke(ConsumeUsageRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('usage:write')) {
            return ProblemDetails::forbidden('The usage:write scope is required.');
        }

        /** @var array{customer_ref?: string|null, meter: string, quantity: int, idempotency_key: string, occurred_at?: string|null, mode?: string|null} $data */
        $data = $request->validated();

        $decision = $this->consumeUsage->consume(new ConsumeUsageCommand(
            projectId: $project->projectId,
            customerRef: $data['customer_ref'] ?? null,
            meter: $data['meter'],
            quantity: $data['quantity'],
            idempotencyKey: $data['idempotency_key'],
            source: 'api',
            mode: $data['mode'] ?? 'enforce',
            occurredAt: $data['occurred_at'] ?? null,
            correlationId: TraceContext::correlationId(),
        ));

        $body = [
            'status' => $decision->status,
            'meter' => $decision->meter,
            'used' => $decision->used,
            'limit' => $decision->limit,
            'remaining' => $decision->remaining(),
            'window_start' => $decision->windowStart,
            'window_end' => $decision->windowEnd,
        ];

        return match ($decision->status) {
            UsageDecision::ADMITTED => new JsonResponse($body, 201),
            UsageDecision::REPLAYED => new JsonResponse($body, 200),
            UsageDecision::QUOTA_EXCEEDED => ProblemDetails::make(
                'quota-exceeded',
                'Quota exceeded',
                429,
                sprintf('The plan allows %s unit(s) of "%s" in this window; %d already used.', $decision->limit ?? 0, $decision->meter, $decision->used),
                $body,
            ),
            UsageDecision::UNDERFLOW => ProblemDetails::make(
                'usage-underflow',
                'Usage cannot go below zero',
                422,
                'The release is larger than what the counter holds.',
                $body,
            ),
            UsageDecision::KEY_CONFLICT => ProblemDetails::make(
                'idempotency-key-reused',
                'Idempotency key already used',
                409,
                'This idempotency_key was already used for a different customer or quantity. Use a new key.',
            ),
            UsageDecision::ENTITLEMENT_INACTIVE => ProblemDetails::make(
                'entitlement-inactive',
                'No active entitlement',
                403,
                'The customer has no contract that grants access right now.',
            ),
            UsageDecision::FEATURE_NOT_INCLUDED => ProblemDetails::make(
                'feature-not-included',
                'Feature not included',
                403,
                sprintf('The plan of this customer carries no limit on "%s".', $decision->meter),
            ),
            UsageDecision::CUSTOMER_REF_REQUIRED => ProblemDetails::make(
                'customer-ref-required',
                'Customer reference required',
                422,
                'This project requires every metered call to name its customer.',
            ),
            default => ProblemDetails::make(
                'customer-not-found',
                'Customer not found',
                404,
                'No customer carries this reference in this project.',
            ),
        };
    }
}
