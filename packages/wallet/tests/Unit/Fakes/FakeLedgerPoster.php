<?php

declare(strict_types=1);

namespace Core\Wallet\Tests\Unit\Fakes;

use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\LedgerTransactionRef;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;

final class FakeLedgerPoster implements LedgerPoster
{
    /** @var list<PostLedgerTransactionCommand> */
    public array $posted = [];

    /** @var array<string, LedgerTransactionRef> */
    private array $byIdempotencyKey = [];

    private int $next = 1;

    public function post(PostLedgerTransactionCommand $command): LedgerTransactionRef
    {
        $key = "{$command->projectId}:{$command->idempotencyKey}";

        if (isset($this->byIdempotencyKey[$key])) {
            $existing = $this->byIdempotencyKey[$key];

            return new LedgerTransactionRef($existing->id, $existing->type, $existing->externalRef, wasAlreadyPosted: true);
        }

        $this->posted[] = $command;

        $ref = new LedgerTransactionRef('ledger-tx-'.$this->next++, $command->type, $command->externalRef, wasAlreadyPosted: false);
        $this->byIdempotencyKey[$key] = $ref;

        return $ref;
    }

    public function hasBeenPosted(string $projectId, string $idempotencyKey): bool
    {
        return isset($this->byIdempotencyKey["{$projectId}:{$idempotencyKey}"]);
    }
}
