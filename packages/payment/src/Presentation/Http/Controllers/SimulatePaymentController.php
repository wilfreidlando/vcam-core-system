<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Payment\Contracts\SimulatePayment;
use Core\Payment\Contracts\SimulatePaymentCommand;
use Core\Payment\Domain\Exception\PaymentNotFound;
use Core\Payment\Domain\Exception\PaymentNotSimulable;
use Core\Payment\Presentation\Http\Requests\SimulatePaymentRequest;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class SimulatePaymentController
{
    public function __construct(
        private SimulatePayment $simulate,
    ) {}

    /**
     * Simulate the outcome of a payment.
     *
     * Only on a deployment where the payment simulator is enabled (the staging
     * deployment, never production). Moves a payment processed by the built-in
     * simulator — i.e. created while your project had no application registered at
     * the provider — to the outcome you choose, exactly as a real provider callback
     * would: same status change, same wallet credit, same `payment.status_changed`
     * webhook or `callback_url` call. Use it to test your whole integration without
     * real money. Requires `payments:write` and an `Idempotency-Key` header.
     *
     * @throws PaymentNotFound the payment doesn't exist or belongs to another project (404)
     * @throws PaymentNotSimulable the simulator is disabled, the payment was not simulated, or it is already final (409)
     */
    public function __invoke(string $paymentId, SimulatePaymentRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('payments:write')) {
            return ProblemDetails::forbidden('The payments:write scope is required.');
        }

        /** @var array{status: string, reason?: string|null} $data */
        $data = $request->validated();

        $view = $this->simulate->simulate(new SimulatePaymentCommand(
            status: $data['status'],
            paymentId: $paymentId,
            projectId: $project->projectId,
            reason: $data['reason'] ?? null,
        ));

        return new JsonResponse([
            'id' => $view->id,
            'status' => $view->status,
        ]);
    }
}
