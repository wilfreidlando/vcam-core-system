<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\ListOperators;
use Core\Identity\Contracts\ListOperatorsCommand;
use Core\Identity\Contracts\OperatorPage;
use Core\Identity\Contracts\OperatorSummaryView;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;

final readonly class ListOperatorsUseCase implements ListOperators
{
    public function __construct(
        private OperatorRepository $operators,
    ) {}

    public function list(ListOperatorsCommand $command): OperatorPage
    {
        $result = $this->operators->findAll($command->page, $command->perPage, $command->search);

        $items = array_map(
            static fn (Operator $operator): OperatorSummaryView => new OperatorSummaryView(
                id: $operator->id,
                email: $operator->email,
                name: $operator->name,
                role: $operator->role->value,
                mfaEnabled: $operator->mfaEnabled(),
                revoked: $operator->isRevoked(),
                createdAt: $operator->createdAt,
            ),
            $result['items'],
        );

        return new OperatorPage($items, $result['total'], $command->page, $command->perPage);
    }
}
