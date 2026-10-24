<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Application\Support\MoneyExceptionViews;
use Core\Payment\Contracts\ListMoneyExceptions;
use Core\Payment\Contracts\ListMoneyExceptionsCommand;
use Core\Payment\Contracts\MoneyExceptionPage;
use Core\Payment\Contracts\MoneyExceptionView;
use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\MoneyExceptionStatus;
use Core\Payment\Domain\Port\MoneyExceptionRepository;
use InvalidArgumentException;

final readonly class ListMoneyExceptionsUseCase implements ListMoneyExceptions
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private MoneyExceptionRepository $exceptions) {}

    public function list(ListMoneyExceptionsCommand $command): MoneyExceptionPage
    {
        $status = null;
        if ($command->status !== null) {
            $status = MoneyExceptionStatus::tryFrom($command->status)
                ?? throw new InvalidArgumentException(sprintf('Unknown money exception status "%s".', $command->status));
        }

        $page = max(1, $command->page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $command->perPage));
        $result = $this->exceptions->list($command->projectIds, $status, $page, $perPage);

        return new MoneyExceptionPage(
            items: array_map(static fn (MoneyException $e): MoneyExceptionView => MoneyExceptionViews::from($e), $result['items']),
            total: $result['total'],
            page: $page,
            perPage: $perPage,
        );
    }

    public function find(string $exceptionId): ?MoneyExceptionView
    {
        $exception = $this->exceptions->find($exceptionId);

        return $exception === null ? null : MoneyExceptionViews::from($exception);
    }

    public function countUnresolved(?array $projectIds = null): int
    {
        return $this->exceptions->countUnresolved($projectIds);
    }
}
