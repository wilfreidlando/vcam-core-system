<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\PurchaseSubscriptionSupplement;
use Core\Billing\Contracts\PurchaseSubscriptionSupplementCommand;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Requests\PurchaseSubscriptionSupplementRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/subscriptions/{id}/supplements` — ADR-0117 §4.
 *
 * The purchase of units added **on top of** the limit of a metered
 * feature: the customer keeps his plan, his period and his counter; what
 * is bought is an allowance, and it carries its own invoice.
 *
 * The route carries the `idempotency` middleware (G7): the purchase
 * issues an invoice and books a receivable, so a replayed request must
 * not buy twice.
 */
final class PurchaseSubscriptionSupplementController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly PurchaseSubscriptionSupplement $purchase,
    ) {}

    /**
     * Buy units added on top of the limit of a metered feature.
     *
     * Answers the supplement and the invoice that carries it. The
     * allowance is granted **when that invoice is paid**, never before.
     *
     * Requires the `subscriptions:write` scope — the gesture is one on a
     * contract, exactly like a change of plan (ADR-0072 §5).
     *
     * @throws \Core\Billing\Domain\Exception\SubscriptionNotFound no contract carries this id in this project (404)
     * @throws \Core\Billing\Domain\Exception\InvalidSubscriptionSupplement the contract grants no right, the meter is not one of its plan, or it has no limit (422)
     */
    #[ScrambleResponse(status: 201, type: BillingResponses::SUPPLEMENT_PURCHASE, description: 'The supplement, pending, and the invoice that carries it.')]
    public function __invoke(
        PurchaseSubscriptionSupplementRequest $request,
        string $subscriptionId,
        ProjectContext $project,
    ): JsonResponse|Response {
        if (! $project->hasScope('subscriptions:write')) {
            return ProblemDetails::forbidden('The subscriptions:write scope is required.');
        }

        /** @var array{meter: string, quantity: int, unit_price_minor: int, description?: string|null} $data */
        $data = $request->validated();

        $view = $this->purchase->purchase(new PurchaseSubscriptionSupplementCommand(
            projectId: $project->projectId,
            subscriptionId: $subscriptionId,
            meter: $data['meter'],
            quantity: $data['quantity'],
            unitPriceMinor: $data['unit_price_minor'],
            description: $data['description'] ?? null,
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse([
            'supplement' => $this->supplementView($view->supplement),
            'invoice' => $this->invoiceView($view->invoice),
        ], 201);
    }
}
