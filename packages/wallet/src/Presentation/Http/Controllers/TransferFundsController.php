<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Wallet\Contracts\TransferFunds;
use Core\Wallet\Contracts\TransferFundsCommand;
use Core\Wallet\Contracts\TransferLeg;
use Core\Wallet\Domain\Exception\InsufficientFunds;
use Core\Wallet\Domain\Exception\InvalidWalletOperation;
use Core\Wallet\Presentation\Http\Presenters\WalletJson;
use Core\Wallet\Presentation\Http\Requests\TransferFundsRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class TransferFundsController
{
    public const METADATA_MAX_BYTES = 4096;

    public function __construct(
        private readonly TransferFunds $transfer,
    ) {}

    /**
     * Transfer funds between wallets.
     *
     * Moves value between wallets of your project, in the environment of your key, as one
     * atomic operation of 1 to 20 legs: every leg lands, or none does. A transfer never
     * creates value — the only way value enters a wallet is a confirmed payment with
     * `wallet_recipient_external_ref`. Parties are named by your own references; a
     * destination's wallet is created on its first credit, a source must already hold
     * enough available funds. Requires the `wallets:write` scope and an `Idempotency-Key`
     * header: replaying the same key returns the original transfer and moves nothing.
     *
     * Each wallet whose balance changed emits `wallet.credited` or `wallet.debited`.
     *
     * @throws InsufficientFunds a source does not hold enough available funds; nothing moved (409)
     * @throws InvalidWalletOperation the legs break a rule (a party paying itself, an unsupported currency…) (422)
     */
    public function __invoke(TransferFundsRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('wallets:write')) {
            return ProblemDetails::forbidden('The wallets:write scope is required.');
        }

        /** @var array{currency: string, legs: list<array{from: string, to: string, amount: int, to_party_type?: string|null}>, description?: string|null, metadata?: array<string, mixed>|null} $data */
        $data = $request->validated();
        $metadata = $data['metadata'] ?? [];
        if (strlen((string) json_encode($metadata)) > self::METADATA_MAX_BYTES) {
            return ProblemDetails::make('validation-failed', 'The request payload is invalid', 422, 'metadata must not exceed 4 KB once encoded.', ['errors' => ['metadata' => ['metadata must not exceed 4 KB once encoded.']]]);
        }

        $operation = $this->transfer->transfer(new TransferFundsCommand(
            $project->projectId,
            $project->environment->value,
            (string) $request->header('Idempotency-Key'),
            strtoupper($data['currency']),
            array_map(static fn (array $leg): TransferLeg => new TransferLeg(
                $leg['from'],
                $leg['to'],
                $leg['amount'],
                $leg['to_party_type'] ?? null,
            ), $data['legs']),
            $data['description'] ?? null,
            $metadata,
        ));

        return new JsonResponse(WalletJson::operation($operation), 201);
    }
}
