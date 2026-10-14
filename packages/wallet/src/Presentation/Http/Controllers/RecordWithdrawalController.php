<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Wallet\Contracts\RecordWithdrawal;
use Core\Wallet\Contracts\RecordWithdrawalCommand;
use Core\Wallet\Domain\Exception\InsufficientFunds;
use Core\Wallet\Domain\Exception\InvalidWalletOperation;
use Core\Wallet\Presentation\Http\Presenters\WalletJson;
use Core\Wallet\Presentation\Http\Requests\RecordWithdrawalRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class RecordWithdrawalController
{
    public function __construct(
        private readonly RecordWithdrawal $withdrawal,
    ) {}

    /**
     * Record a withdrawal.
     *
     * Records that value left your custody: a payout you made outside the Core (a mobile money
     * transfer, a bank wire), whose proof goes in `external_reference`. The party's wallet is
     * debited; no value is created. Requires the `wallets:write` scope and an `Idempotency-Key`
     * header. Emits `wallet.debited`.
     *
     * @throws InsufficientFunds the wallet does not hold enough available funds; nothing moved (409)
     * @throws InvalidWalletOperation unsupported currency or invalid amount (422)
     */
    public function __invoke(RecordWithdrawalRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('wallets:write')) {
            return ProblemDetails::forbidden('The wallets:write scope is required.');
        }

        /** @var array{party: string, amount: int, currency: string, description?: string|null, external_reference?: string|null, metadata?: array<string, mixed>|null} $data */
        $data = $request->validated();
        $metadata = $data['metadata'] ?? [];
        if (strlen((string) json_encode($metadata)) > TransferFundsController::METADATA_MAX_BYTES) {
            return ProblemDetails::make('validation-failed', 'The request payload is invalid', 422, 'metadata must not exceed 4 KB once encoded.', ['errors' => ['metadata' => ['metadata must not exceed 4 KB once encoded.']]]);
        }

        $operation = $this->withdrawal->record(new RecordWithdrawalCommand(
            $project->projectId,
            $project->environment->value,
            (string) $request->header('Idempotency-Key'),
            $data['party'],
            $data['amount'],
            strtoupper($data['currency']),
            $data['description'] ?? null,
            $data['external_reference'] ?? null,
            $metadata,
        ));

        return new JsonResponse(WalletJson::operation($operation), 201);
    }
}
