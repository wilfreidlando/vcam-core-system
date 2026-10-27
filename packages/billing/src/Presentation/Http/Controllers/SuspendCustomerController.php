<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\SuspendCustomer;
use Core\Billing\Contracts\SuspendCustomerCommand;
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
 * `POST /v1/customers/{externalRef}/suspend` — ADR-0070 §2.
 *
 * The customer loses its rights (ADR-0072 §3) but its invoices stay
 * due.
 */
final class SuspendCustomerController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly SuspendCustomer $suspendCustomer,
    ) {}

    /**
     * Suspend a customer of the calling Project.
     *
     * The customer keeps its invoices due but loses its rights: it can
     * no longer read what it subscribed to (ADR-0072 §3). An erased
     * customer is final and cannot be suspended.
     *
     * Requires the `customers:write` scope.
     *
     * @throws CustomerNotFound the customer doesn't exist, or belongs to another project (404)
     * @throws InvalidCustomerTransition the customer is erased and cannot be suspended (409)
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::CUSTOMER, description: 'The suspended customer.')]
    public function __invoke(string $externalRef, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('customers:write')) {
            return ProblemDetails::forbidden('The customers:write scope is required.');
        }

        $view = $this->suspendCustomer->suspend(new SuspendCustomerCommand(
            projectId: $project->projectId,
            externalRef: $externalRef,
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse($this->customerView($view, $project->hasScope('customers:read')));
    }
}
