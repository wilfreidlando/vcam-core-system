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
use Core\Support\Access\AccessScope;
use InvalidArgumentException;

final readonly class ListMoneyExceptionsUseCase implements ListMoneyExceptions
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private MoneyExceptionRepository $exceptions) {}

    public function list(ListMoneyExceptionsCommand $command, AccessScope $scope): MoneyExceptionPage
    {
        $status = null;
        if ($command->status !== null) {
            $status = MoneyExceptionStatus::tryFrom($command->status)
                ?? throw new InvalidArgumentException(sprintf('Unknown money exception status "%s".', $command->status));
        }

        $page = max(1, $command->page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $command->perPage));

        // ADR-0069 §2 — the exceptions of a project outside the account's
        // scope are not listed, whatever the caller asked for.
        $projectIds = $this->restrict($command->projectIds, $scope);

        $result = $this->exceptions->list($projectIds, $status, $page, $perPage);

        return new MoneyExceptionPage(
            items: array_map(static fn (MoneyException $e): MoneyExceptionView => MoneyExceptionViews::from($e), $result['items']),
            total: $result['total'],
            page: $page,
            perPage: $perPage,
        );
    }

    public function find(string $exceptionId, AccessScope $scope): ?MoneyExceptionView
    {
        $exception = $this->exceptions->find($exceptionId);

        if ($exception === null) {
            return null;
        }

        // ADR-0069 §2 — an exception of another tenant is "not found".
        if (! $scope->allowsProject($exception->projectId)) {
            return null;
        }

        return MoneyExceptionViews::from($exception);
    }

    public function countUnresolved(AccessScope $scope): int
    {
        return $this->exceptions->countUnresolved($scope->projectFilter());
    }

    /**
     * The projects to read: the caller's choice, restricted to the scope.
     * `null` means "every project" — which only a platform scope may have.
     *
     * @param list<string>|null $requested
     * @return list<string>|null
     */
    private function restrict(?array $requested, AccessScope $scope): ?array
    {
        $allowed = $scope->projectFilter();

        if ($allowed === null) {
            return $requested;
        }

        if ($requested === null) {
            return $allowed;
        }

        return array_values(array_intersect($requested, $allowed));
    }
}
