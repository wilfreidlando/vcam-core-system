<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fitness function 7 — a monetary mutation cannot be exposed without
 * idempotency.
 *
 * Mobile money makes retries routine: a consumer times out, the network drops a
 * response, a queue redelivers a job. Without an idempotency key, the second
 * attempt charges the customer twice — and a double debit is not a bug report,
 * it is a refund, an apology and a loss of trust.
 *
 * The guard is structural: any route that mutates money must pass through the
 * idempotency middleware. New payment endpoints therefore cannot forget it,
 * because the build fails before review does.
 */
#[Group('arch')]
final class IdempotencyTest extends TestCase
{
    /**
     * Routes whose URI matches this are treated as monetary mutations.
     * A route may also opt in explicitly via the MonetaryMutation attribute.
     */
    private const MONETARY_URI_PATTERN = '#(payments|refunds|payouts|transfers|charges|deposits|withdrawals|wallets/[^/]+/(debit|credit|hold)|ledger/transactions)#i';

    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const IDEMPOTENCY_MIDDLEWARE = 'idempotency';

    #[Test]
    public function every_monetary_mutation_route_requires_an_idempotency_key(): void
    {
        $monetary = array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn (Route $route): bool => $this->isMonetaryMutation($route),
        );

        if ($monetary === []) {
            self::markTestSkipped(
                'No monetary route exists yet (Lot 3). Registered as G7 in '.
                'docs/architecture/garde-fous-inactifs.md; this guard activates with the first payment endpoint.'
            );
        }

        $offenders = [];

        foreach ($monetary as $route) {
            /** @var list<string> $middlewareNames */
            $middlewareNames = $route->gatherMiddleware();
            $middleware = implode(' ', $middlewareNames);

            if (! str_contains($middleware, self::IDEMPOTENCY_MIDDLEWARE)) {
                $offenders[] = sprintf(
                    '%s %s',
                    implode('|', array_intersect($this->methodsOf($route), self::MUTATING_METHODS)),
                    $route->uri(),
                );
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                "These routes mutate money without requiring an Idempotency-Key:\n  - %s\n\n".
                "Apply the `%s` middleware. Replaying the same key must return the original\n".
                'result and must never move money a second time.',
                implode("\n  - ", $offenders),
                self::IDEMPOTENCY_MIDDLEWARE,
            ),
        );
    }

    private function isMonetaryMutation(Route $route): bool
    {
        $mutates = array_intersect($this->methodsOf($route), self::MUTATING_METHODS) !== [];

        if (! $mutates) {
            return false;
        }

        return preg_match(self::MONETARY_URI_PATTERN, $route->uri()) === 1;
    }

    /**
     * @return list<string>
     */
    private function methodsOf(Route $route): array
    {
        /** @var list<string> $methods */
        $methods = $route->methods();

        return $methods;
    }
}
