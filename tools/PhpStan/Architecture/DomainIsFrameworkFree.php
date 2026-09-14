<?php

declare(strict_types=1);

namespace Core\Tooling\PhpStan\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Fitness function 3b — the Domain layer of every bounded context is pure PHP.
 *
 * Deptrac already forbids Domain from reaching Application or Infrastructure,
 * but it reasons about namespaces only. PHPat is type-aware: it also catches a
 * domain entity that extends an Eloquent model, implements Arrayable, or
 * type-hints a framework contract through an interface.
 *
 * Why this matters beyond tidiness: the technical specification promises that a
 * module can later be extracted into its own service "without a rewrite". That
 * promise is only real if the business rules carry no framework with them.
 */
final class DomainIsFrameworkFree
{
    private const DOMAIN_NAMESPACE = '/^Core\\\\[A-Za-z]+\\\\Domain(\\\\|$)/';

    public function test_domain_must_not_depend_on_the_framework(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::DOMAIN_NAMESPACE, true))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Laravel'),
                Selector::inNamespace('Symfony'),
                Selector::inNamespace('Carbon'),
                Selector::inNamespace('Psr\Http'),
                Selector::classname('PDO'),
                Selector::classname('PDOStatement'),
            )
            ->because(
                'Domain is pure PHP: entities, value objects and ports only. '.
                'Persistence and HTTP concerns belong in Infrastructure, behind a Domain port.'
            );
    }

    /**
     * Contracts are the published surface other modules consume. If a contract
     * leaks a framework type, every consumer inherits that coupling and the
     * interface can no longer be honoured by a future extracted service.
     */
    public function test_contracts_must_not_leak_framework_types(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('/^Core\\\\[A-Za-z]+\\\\Contracts(\\\\|$)/', true))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Laravel'),
            )
            ->because(
                'A published contract must be implementable without Laravel. '.
                'Expose scalars, enums and DTOs, never Eloquent models or framework collections.'
            );
    }
}
