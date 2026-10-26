<?php

declare(strict_types=1);

namespace Core\Tooling\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\TypeUtils;

/**
 * Fitness function — ADR-0069 §2 : « Tout port de listing ou de lecture
 * utilisé par la console reçoit l'`AccessScope` en **paramètre requis** (pas
 * de valeur par défaut). Un port qui ne le prend pas ne peut pas être appelé
 * depuis `admin` : c'est une règle PHPStan (`AdminQueriesAreScoped`). »
 *
 * The rule is structural, not a review checklist: a console controller that
 * calls a read port whose contract has no scope fails the build. The
 * signature is read from PHPStan's own reflection, so the rule can never
 * drift from the contract it protects.
 *
 * Ports are recognised by two things at once:
 *
 *  - they live in a module's `Contracts/` namespace (the only cross-module
 *    surface, G3c) and are interfaces (a port is a contract, never a DTO);
 *  - their short name follows the read-port naming convention
 *    (`List*`, `Get*`, `Count*`, `*Query`, `*Stats`, `*Summary`, …).
 *
 * The convention is what makes the rule future-proof: a `ListWhatever` port
 * added to a module next year is caught the day the console calls it,
 * without anybody remembering to update a list of port names.
 *
 * @implements Rule<MethodCall>
 */
final class AdminQueriesAreScopedRule implements Rule
{
    /**
     * Where the console lives: the admin module's JSON plane, the web
     * console's controllers, and the view composers that feed its
     * templates (a template itself is invisible to static analysis — see
     * `tests/Architecture/ConsoleViewsCallNoPortTest`).
     */
    private const ADMIN_NAMESPACES = [
        'Core\\Admin',
        'App\\Http\\Controllers\\Admin',
        'App\\Http\\View',
    ];

    /**
     * A port whose name starts with one of these reads something.
     */
    private const READ_PORT_PREFIXES = [
        'List',
        'Get',
        'Count',
        'Find',
        'Search',
        'Read',
        'Export',
        'Fetch',
        'Browse',
    ];

    /**
     * A port whose name ends with one of these reads something.
     */
    private const READ_PORT_SUFFIXES = [
        'Query',
        'Stats',
        'Summary',
    ];

    private const SCOPE_CLASS = 'Core\\Support\\Access\\AccessScope';

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace() ?? '';

        if (! self::isAdminNamespace($namespace)) {
            return [];
        }

        if (! $node->name instanceof Identifier) {
            return [];
        }

        $method = $node->name->toString();
        $calledOn = $scope->getType($node->var);

        if (! $calledOn->isObject()->yes()) {
            return [];
        }

        foreach ($calledOn->getObjectClassNames() as $className) {
            foreach ($this->readPortsOf($className) as $port) {
                $error = $this->check($port, $method);

                if ($error !== null) {
                    return [$error];
                }
            }
        }

        return [];
    }

    private static function isAdminNamespace(string $namespace): bool
    {
        foreach (self::ADMIN_NAMESPACES as $admin) {
            // The namespace of a file is exactly the console namespace for
            // the controllers that live directly in it, and a child of it
            // for the ones that do not.
            if ($namespace === $admin || str_starts_with($namespace, $admin.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The read ports implemented by the given class.
     *
     * @return list<ClassReflection>
     */
    private function readPortsOf(string $className): array
    {
        if (! $this->reflectionProvider->hasClass($className)) {
            return [];
        }

        $class = $this->reflectionProvider->getClass($className);

        $candidates = [];

        if ($class->isInterface()) {
            $candidates[] = $class;
        }

        foreach ($class->getInterfaces() as $interface) {
            $candidates[] = $interface;
        }

        $ports = [];

        foreach ($candidates as $candidate) {
            $name = $candidate->getName();

            if (! self::isContract($name) || ! self::isReadPort($name)) {
                continue;
            }

            $ports[] = $candidate;
        }

        return $ports;
    }

    /**
     * A port is a contract: it lives in a module's `Contracts/` namespace.
     */
    private static function isContract(string $name): bool
    {
        return str_starts_with($name, 'Core\\') && str_contains($name, '\\Contracts\\');
    }

    /**
     * A port is a read port when its name says so.
     */
    private static function isReadPort(string $name): bool
    {
        $short = substr((string) strrchr($name, '\\'), 1);

        foreach (self::READ_PORT_PREFIXES as $prefix) {
            if (str_starts_with($short, $prefix)) {
                return true;
            }
        }

        foreach (self::READ_PORT_SUFFIXES as $suffix) {
            if (str_ends_with($short, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function check(ClassReflection $port, string $method): ?\PHPStan\Rules\IdentifierRuleError
    {
        $name = $port->getName();

        if (! $port->hasNativeMethod($method)) {
            // The console calls a method the port does not declare: the
            // port is not the contract being used. Nothing to enforce here.
            return null;
        }

        foreach ($port->getNativeMethod($method)->getVariants() as $variant) {
            $scopeParameters = [];

            foreach ($variant->getParameters() as $parameter) {
                if (self::mentionsScope($parameter->getType())) {
                    $scopeParameters[] = $parameter;
                }
            }

            if ($scopeParameters === []) {
                // This variant of the method takes no scope at all: the
                // console could call it without one.
                return RuleErrorBuilder::message(sprintf(
                    'The console calls %s::%s(), a read port that takes no access scope.',
                    $name,
                    $method,
                ))
                    ->identifier('core.adminQueryWithoutScope')
                    ->tip('ADR-0069 §2: the scope is a REQUIRED parameter of every read port the console uses. Add `AccessScope $scope` to the port (no default value) and pass `$operator->scope` from the console, `AccessScope::forProject($projectId)` from the public API.')
                    ->build();
            }

            foreach ($scopeParameters as $parameter) {
                // ADR-0069 §2 says it twice: "en **paramètre requis** (pas
                // de valeur par défaut)". A defaulted scope is a hole —
                // the console may call the port without one, and the port
                // then reads everything.
                if ($parameter->isOptional()) {
                    return RuleErrorBuilder::message(sprintf(
                        'The console calls %s::%s(), a read port whose access scope is not a required parameter.',
                        $name,
                        $method,
                    ))
                        ->identifier('core.adminScopeIsOptional')
                        ->tip('ADR-0069 §2: the scope is a REQUIRED parameter of every read port the console uses — no default value. Remove the default so the scope cannot be omitted.')
                        ->build();
                }
            }
        }

        return null;
    }

    private static function mentionsScope(Type $type): bool
    {
        // A nullable scope (`?AccessScope $scope = null`) is a union:
        // `getObjectClassNames()` alone does not see through it, and the
        // rule would then report the port as "no scope at all" instead of
        // "the scope is not required" — the wrong message for the wrong
        // reason.
        foreach (TypeUtils::flattenTypes($type) as $inner) {
            foreach ($inner->getObjectClassNames() as $className) {
                if ($className === self::SCOPE_CLASS) {
                    return true;
                }
            }
        }

        return false;
    }
}
