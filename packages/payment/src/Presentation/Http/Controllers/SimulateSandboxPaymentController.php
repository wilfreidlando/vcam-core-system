<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Payment\Contracts\SimulateSandboxPayment;
use Core\Payment\Contracts\SimulateSandboxPaymentCommand;
use Core\Payment\Presentation\Http\Requests\SimulateSandboxPaymentRequest;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class SimulateSandboxPaymentController
{
    public function __construct(
        private SimulateSandboxPayment $simulate,
    ) {}

    /**
     * Simulate the outcome of a sandbox payment.
     *
     * Sandbox keys only. Moves a payment created with a sandbox key (and
     * processed by the built-in simulator, i.e. no sandbox provider
     * application registered) to the outcome you choose — exactly as a real
     * provider callback would: same status change, same `payment.status_changed`
     * webhook or `callback_url` call. Use it to test your whole integration,
     * webhooks included, without real money. Requires `payments:write`.
     */
    public function __invoke(string $paymentId, SimulateSandboxPaymentRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('payments:write')) {
            return ProblemDetails::forbidden('The payments:write scope is required.');
        }

        if ($project->environment->isLive()) {
            return ProblemDetails::forbidden('Simulation is only available with a sandbox API key.');
        }

        /** @var array{status: string, reason?: string|null} $data */
        $data = $request->validated();

        $view = $this->simulate->simulate(new SimulateSandboxPaymentCommand(
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
