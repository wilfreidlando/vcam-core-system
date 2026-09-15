<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Contracts\InitiatePaymentCommand;
use Core\Payment\Contracts\PaymentAction;
use Core\Payment\Contracts\PaymentGateway;
use Core\Payment\Contracts\PaymentReceipt;
use Core\Payment\PaymentStatus;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Provider\ProviderCode;
use LogicException;
use Throwable;

final class FakePaymentGateway implements PaymentGateway
{
    /** @var list<InitiatePaymentCommand> */
    public array $initiated = [];

    public PaymentReceipt $nextReceipt;

    /**
     * When set, `checkStatus()` throws this instead of returning
     * `$nextReceipt` — models a provider call failing during
     * reconciliation (ReconcileStuckPaymentsUseCase).
     */
    public ?Throwable $checkStatusException = null;

    public function __construct(
        private readonly ProviderCode $code,
    ) {
        $this->nextReceipt = new PaymentReceipt('tx-fake-1', PaymentStatus::Pending);
    }

    public function initiate(InitiatePaymentCommand $command): PaymentReceipt
    {
        $this->initiated[] = $command;

        return $this->nextReceipt;
    }

    public function submitAction(PaymentAction $action): PaymentReceipt
    {
        return $this->nextReceipt;
    }

    public function checkStatus(string $transactionRef): PaymentReceipt
    {
        if ($this->checkStatusException !== null) {
            throw $this->checkStatusException;
        }

        return $this->nextReceipt;
    }

    public function providerCode(): ProviderCode
    {
        return $this->code;
    }

    public function config(): ProviderConfig
    {
        throw new LogicException('Not used by this fake.');
    }
}
