<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Payment\Application\Port\SandboxPaymentSimulator;
use Core\Payment\Contracts\SimulateSandboxPayment;
use Core\Payment\Contracts\SimulateSandboxPaymentCommand;
use Core\Payment\Domain\Port\PaymentRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0060 — the hosted "payment page" of a sandbox paylink. The signed,
 * expiring URL is the only credential (the payer has no API key): it shows
 * the amount and lets the tester pick the outcome.
 */
final readonly class SandboxCheckoutController
{
    public function __construct(
        private PaymentRepository $payments,
        private SimulateSandboxPayment $simulate,
    ) {}

    public function show(string $transactionRef): Response
    {
        $payment = $this->payments->findByProviderTransactionRef(SandboxPaymentSimulator::PROVIDER_CODE, $transactionRef);
        if ($payment === null) {
            abort(404);
        }

        return response()->view('payment::sandbox-checkout', [
            'payment' => $payment,
            'action' => URL::temporarySignedRoute('sandbox.checkout.complete', now()->addHour(), ['transactionRef' => $transactionRef]),
            'outcome' => null,
        ]);
    }

    public function complete(string $transactionRef, Request $request): Response
    {
        $input = $request->input('status');
        $status = is_string($input) ? $input : '';

        try {
            $view = $this->simulate->simulate(new SimulateSandboxPaymentCommand(status: $status, transactionRef: $transactionRef));
        } catch (RuntimeException|InvalidArgumentException $e) {
            return response()->view('payment::sandbox-checkout', ['payment' => null, 'action' => null, 'outcome' => $e->getMessage()], 422);
        }

        $payment = $this->payments->find($view->id);

        return response()->view('payment::sandbox-checkout', [
            'payment' => $payment,
            'action' => null,
            'outcome' => $view->status,
        ]);
    }
}
