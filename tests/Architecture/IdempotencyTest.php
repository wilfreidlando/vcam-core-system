<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Http\Middleware\ResolveOperatorSession;
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
     *
     * `invoices` is here since Lot 11 (ADR-0094 §8): the issuance books
     * the receivable in the ledger and the cancellation counter-passes
     * it — both of them move money, and a replayed issuance would book a
     * second receivable against the same number.
     */
    private const MONETARY_URI_PATTERN = '#(payments|refunds|payouts|transfers|charges|deposits|withdrawals|invoices|wallets/[^/]+/(debit|credit|hold)|ledger/transactions)#i';

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

        // The **web console** (`/admin/*`, session + cookie + CSRF) is
        // not part of this guard: a browser form does not carry an
        // `Idempotency-Key` header. Its gestures are protected by the
        // CSRF token, by the audit trail and by the domain's own
        // refusals — issuing an invoice twice is refused the second
        // time, because it is no longer a draft.
        if ($this->isWebConsole($route)) {
            return false;
        }

        return preg_match(self::MONETARY_URI_PATTERN, $route->uri()) === 1;
    }

    /**
     * The route belongs to the HTML console: it is guarded by the
     * operator's session, not by a bearer token.
     */
    private function isWebConsole(Route $route): bool
    {
        $middleware = array_values(array_filter($route->gatherMiddleware(), is_string(...)));

        return array_intersect(['operator.session', ResolveOperatorSession::class], $middleware) !== [];
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
