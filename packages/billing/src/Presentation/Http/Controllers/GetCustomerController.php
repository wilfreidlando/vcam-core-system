<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Billing\Contracts\GetCustomerDeclaredAttributes;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/customers/{externalRef}` — ADR-0070 §2.
 *
 * The customer of the calling project, read by the reference the
 * project knows it by. A customer of another project is simply not
 * found — never told apart from one that does not exist
 * (ADR-0070 §Sécurité).
 */
final class GetCustomerController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly GetCustomer $getCustomer,
        private readonly GetCustomerDeclaredAttributes $declaredAttributes,
    ) {}

    /**
     * Get a customer of the calling Project.
     *
     * Answers the customer, with its version in an `ETag` header — the
     * caller sends it back in `If-Match` on its next write.
     *
     * Requires the `customers:read` scope — the only scope that carries
     * the email and the phone out of the Core (ADR-0070 §Sécurité).
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::CUSTOMER, description: 'The customer.')]
    public function __invoke(string $externalRef, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('customers:read')) {
            return ProblemDetails::forbidden('The customers:read scope is required.');
        }

        $view = $this->getCustomer->get(
            new GetCustomerCommand($project->projectId, $externalRef),
            // ADR-0069 §2 — the public API plane's scope is the calling
            // project itself: a customer of another project is not found.
            AccessScope::forProject($project->projectId),
        );

        if ($view === null) {
            return ProblemDetails::make(
                'customer-not-found',
                'Customer not found',
                404,
                sprintf('No customer carries the external reference "%s" in this project.', $externalRef),
            );
        }

        // ADR-0122 §4 — the declared values of the tenant travel with the
        // customer: they are read back here, under the same `customers:read`
        // scope that carries the email and the phone.
        $declared = $this->declaredAttributes->forCustomer(
            $project->projectId,
            $view->id,
            AccessScope::forProject($project->projectId),
        );

        $response = new JsonResponse($this->customerView($view, true, $declared));
        $response->headers->set('ETag', (string) $view->version);

        return $response;
    }
}
