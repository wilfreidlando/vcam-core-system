<?php

declare(strict_types=1);

namespace Core\Tooling\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Fitness function 8b — the global helpers that bypass the outbox.
 *
 * EventsOnlyViaOutbox (PHPat) catches dependencies on dispatcher *classes*, but
 * `event()`, `dispatch()` and `broadcast()` are global functions: they create no
 * class dependency and slip through type-aware analysis entirely. They are also
 * the most tempting shortcut, which is exactly why they need a rule.
 *
 * @implements Rule<FuncCall>
 */
final class NoDirectEventDispatchRule implements Rule
{
    private const FORBIDDEN_HELPERS = ['event', 'broadcast', 'dispatch', 'dispatch_sync'];

    /**
     * The relay is the one component whose job is to publish what the outbox
     * already durably recorded.
     */
    private const ALLOWED_NAMESPACE = 'Core\Events\Infrastructure\Relay';

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        $function = $node->name->toLowerString();

        if (! in_array($function, self::FORBIDDEN_HELPERS, true)) {
            return [];
        }

        $namespace = $scope->getNamespace() ?? '';

        if (! str_starts_with($namespace, 'Core\\')) {
            return [];
        }

        if (str_starts_with($namespace, self::ALLOWED_NAMESPACE)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s() publishes outside the transactional outbox.',
                $function,
            ))
                ->identifier('core.eventOutsideOutbox')
                ->tip('Append the event to event_outbox in the same transaction as the state change; the relay publishes it. Otherwise a rollback can leave consumers told about money that does not exist.')
                ->build(),
        ];
    }
}
