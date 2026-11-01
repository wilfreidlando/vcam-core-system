<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\EraseCustomer;
use Core\Billing\Contracts\EraseCustomerCommand;
use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Billing\Domain\Exception\InvalidCustomerTransition;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Requests\EraseCustomerRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/customers/{externalRef}/erase` — ADR-0070 §2.
 *
 * The identity is gone (ADR-0083), the history stays: the row is kept
 * because the invoices reference it, but the personal values are
 * destroyed.
 */
final class EraseCustomerController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly EraseCustomer $eraseCustomer,
    ) {}

    /**
     * Erase a customer of the calling Project.
     *
     * The identity is destroyed — the display name, the email, the
     * phone and the metadata go; the row and the history it carries stay
     * (ADR-0083). The operation is final: an erased customer can be
     * neither suspended nor reactivated.
     *
     * Requires the `customers:write` scope.
     *
     * @throws CustomerNotFound the customer doesn't exist, or belongs to another project (404)
     * @throws InvalidCustomerTransition the customer is already erased (409)
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::CUSTOMER, description: 'The erased customer: its identity is gone, its row stays.')]
    public function __invoke(string $externalRef, EraseCustomerRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('customers:write')) {
            return ProblemDetails::forbidden('The customers:write scope is required.');
        }

        // The reason of the erasure is accepted and validated, but the
        // published contract (`EraseCustomerCommand`) carries no reason
        // field yet: it is not persisted. A future amendment of the
        // contract will carry it to the audit trail.
        /** @var array{reason?: string|null} $data */
        $data = $request->validated();

        $view = $this->eraseCustomer->erase(new EraseCustomerCommand(
            projectId: $project->projectId,
            externalRef: $externalRef,
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse($this->customerView($view, $project->hasScope('customers:read')));
    }
}
