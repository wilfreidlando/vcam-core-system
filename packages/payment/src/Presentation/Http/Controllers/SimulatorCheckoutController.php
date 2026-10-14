<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Payment\Application\Port\PaymentSimulator;
use Core\Payment\Contracts\SimulatePayment;
use Core\Payment\Contracts\SimulatePaymentCommand;
use Core\Payment\Domain\Port\PaymentRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0066 — the hosted "payment page" of a simulated paylink. The signed,
 * expiring URL is the only credential (the payer has no API key): it shows
 * the amount and lets the tester pick the outcome. Answers 404 when the
 * simulator is disabled on this deployment.
 */
final readonly class SimulatorCheckoutController
{
    public function __construct(
        private PaymentRepository $payments,
        private SimulatePayment $simulate,
        private PaymentSimulator $simulator,
    ) {}

    public function show(string $transactionRef): Response
    {
        $payment = $this->simulator->isEnabled()
            ? $this->payments->findByProviderTransactionRef(PaymentSimulator::PROVIDER_CODE, $transactionRef)
            : null;
        if ($payment === null) {
            abort(404);
        }

        return response()->view('payment::simulator-checkout', [
            'payment' => $payment,
            'action' => URL::temporarySignedRoute('simulator.checkout.complete', now()->addHour(), ['transactionRef' => $transactionRef]),
            'outcome' => null,
        ]);
    }

    public function complete(string $transactionRef, Request $request): Response
    {
        $input = $request->input('status');
        $status = is_string($input) ? $input : '';

        try {
            $view = $this->simulate->simulate(new SimulatePaymentCommand(status: $status, transactionRef: $transactionRef));
        } catch (RuntimeException|InvalidArgumentException $e) {
            return response()->view('payment::simulator-checkout', ['payment' => null, 'action' => null, 'outcome' => $e->getMessage()], 422);
        }

        $payment = $this->payments->find($view->id);

        return response()->view('payment::simulator-checkout', [
            'payment' => $payment,
            'action' => null,
            'outcome' => $view->status,
        ]);
    }
}
