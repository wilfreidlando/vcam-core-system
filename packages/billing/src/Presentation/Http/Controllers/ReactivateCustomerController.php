<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\ReactivateCustomer;
use Core\Billing\Contracts\ReactivateCustomerCommand;
use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Billing\Domain\Exception\InvalidCustomerTransition;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/customers/{externalRef}/reactivate` — ADR-0070 §2.
 *
 * The customer gets its rights back (ADR-0072 §3).
 */
final class ReactivateCustomerController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly ReactivateCustomer $reactivateCustomer,
    ) {}

    /**
     * Reactivate a customer of the calling Project.
     *
     * The customer gets its rights back: it reads again what it
     * subscribed to (ADR-0072 §3). An erased customer is final and
     * cannot be reactivated.
     *
     * Requires the `customers:write` scope.
     *
     * @throws CustomerNotFound the customer doesn't exist, or belongs to another project (404)
     * @throws InvalidCustomerTransition the customer is erased and cannot be reactivated (409)
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::CUSTOMER, description: 'The reactivated customer.')]
    public function __invoke(string $externalRef, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('customers:write')) {
            return ProblemDetails::forbidden('The customers:write scope is required.');
        }

        $view = $this->reactivateCustomer->reactivate(new ReactivateCustomerCommand(
            projectId: $project->projectId,
            externalRef: $externalRef,
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse($this->customerView($view, $project->hasScope('customers:read')));
    }
}
