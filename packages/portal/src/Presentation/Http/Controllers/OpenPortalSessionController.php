<?php

declare(strict_types=1);

namespace Core\Portal\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Portal\Application\Dto\OpenPortalSessionCommand;
use Core\Portal\Application\UseCase\OpenPortalSessionUseCase;
use Core\Portal\Presentation\Http\Requests\OpenPortalSessionRequest;
use Core\Portal\Presentation\Http\Responses\PortalResponses;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/portal/sessions` — ADR-0075 §1.
 */
final class OpenPortalSessionController
{
    public function __construct(private readonly OpenPortalSessionUseCase $open) {}

    /**
     * Send a customer to the hosted portal.
     *
     * Returns a link valid **10 minutes** and usable **once**: redirect the
     * customer's browser to it. In the same call you may declare the
     * customer (`customer`) and subscribe it to a plan (`plan_code`, only
     * if it has no live contract on it) — this single call is the whole
     * commerce integration of your login page.
     *
     * Requires `portal:write`; `customer` additionally needs
     * `customers:write` and `plan_code` needs `subscriptions:write`.
     */
    #[ScrambleResponse(status: 201, type: PortalResponses::SESSION, description: 'The one-time link of the portal.')]
    public function __invoke(OpenPortalSessionRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('portal:write')) {
            return ProblemDetails::forbidden('The portal:write scope is required.');
        }

        /** @var array{customer_ref: string, flow?: string|null, invoice_id?: string|null, return_url?: string|null, customer?: array<string, mixed>|null, plan_code?: string|null, trial_days?: int|null} $data */
        $data = $request->validated();
        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : null;
        $planCode = is_string($data['plan_code'] ?? null) ? $data['plan_code'] : null;

        // A key that may only open portals must not also write customers or
        // contracts by the side door: the extra powers need their own scopes.
        if ($customer !== null && ! $project->hasScope('customers:write')) {
            return ProblemDetails::forbidden('Declaring the customer in this call needs the customers:write scope.');
        }

        if ($planCode !== null && ! $project->hasScope('subscriptions:write')) {
            return ProblemDetails::forbidden('Subscribing the customer in this call needs the subscriptions:write scope.');
        }

        /** @var array{display_name?: string|null, email?: string|null, phone?: string|null, locale?: string|null, metadata?: array<string, mixed>|null}|null $customer */
        $opened = $this->open->open(new OpenPortalSessionCommand(
            projectId: $project->projectId,
            customerRef: $data['customer_ref'],
            flow: is_string($data['flow'] ?? null) ? $data['flow'] : 'manage',
            invoiceId: is_string($data['invoice_id'] ?? null) ? $data['invoice_id'] : null,
            returnUrl: is_string($data['return_url'] ?? null) ? $data['return_url'] : null,
            customer: $customer,
            planCode: $planCode,
            trialDays: is_int($data['trial_days'] ?? null) ? $data['trial_days'] : null,
            callerIp: $request->ip(),
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse([
            'url' => $opened->url,
            'expires_at' => $opened->expiresAt,
            'customer_created' => $opened->customerCreated,
            'subscription_created' => $opened->subscriptionCreated,
        ], 201);
    }
}
