<?php

declare(strict_types=1);

namespace Core\Tooling\PhpStan\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Eloquent models and the query builder live in Infrastructure\Persistence and
 * nowhere else.
 *
 * A controller that queries a model directly bypasses the use case, and with it
 * the transaction boundary, the idempotency check and the outbox write. That is
 * precisely how a "small fix" ends up debiting a wallet without a ledger entry.
 */
final class PersistenceStaysInInfrastructure
{
    private const OUTSIDE_INFRASTRUCTURE = '/^Core\\\\[A-Za-z]+\\\\(Domain|Application|Contracts|Presentation)(\\\\|$)/';

    public function test_persistence_must_not_leak_out_of_infrastructure(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::OUTSIDE_INFRASTRUCTURE, true))
            ->shouldNotDependOn()
            ->classes(
                Selector::classname('Illuminate\Database\Eloquent\Model'),
                Selector::classname('Illuminate\Database\Eloquent\Builder'),
                Selector::classname('Illuminate\Database\Query\Builder'),
                Selector::classname('Illuminate\Support\Facades\DB'),
                Selector::classname('Illuminate\Support\Facades\Schema'),
            )
            ->because(
                'Persistence belongs behind a Domain port, implemented in '.
                'Infrastructure\Persistence. Reaching the database directly skips the '.
                'use case, and with it the transaction, idempotency and outbox guarantees.'
            );
    }

    /**
     * ADR-0021 — brick/money is a 0.x dependency whose minor releases are
     * breaking. It is wrapped once, in the shared kernel, so that a future
     * upgrade is a single-file change rather than a repo-wide refactor.
     */
    public function test_money_library_is_wrapped_by_the_shared_kernel(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Core'))
            ->excluding(Selector::inNamespace('Core\Support\Money'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Brick'))
            ->because(
                'Use Core\Support\Money. brick/money is 0.x with breaking minor releases; '.
                'it must not spread across modules.'
            );
    }
}
