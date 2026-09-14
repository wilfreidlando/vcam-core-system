<?php

declare(strict_types=1);

namespace Core\Tooling\PhpStan\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Guardrail G6c — only the shared integration caller speaks HTTP to the outside.
 *
 * Every provider call must carry the same guarantees: bounded timeouts, bounded
 * retries, secret redaction in logs, correlation-id propagation, idempotency
 * keys, and a persisted record of the attempt. When each adapter builds its own
 * `Http::post()`, those guarantees are re-implemented per provider — and the bug
 * fixed in the MTN adapter survives in the Orange one.
 *
 * The caller itself is built in Lot 3 from the two real MVP providers (ADR-0026),
 * not designed in advance. Until it exists this rule means: no module may open an
 * HTTP connection to anything.
 *
 * @see docs/adr/0026-integration-providers-externes.md
 */
final class OutboundHttpIsCentralised
{
    public function test_only_the_integration_caller_opens_outbound_http(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Core'))
            ->excluding(Selector::inNamespace('Core\Support\Integration'))
            ->shouldNotDependOn()
            ->classes(
                Selector::classname('Illuminate\Support\Facades\Http'),
                Selector::inNamespace('Illuminate\Http\Client'),
                Selector::inNamespace('GuzzleHttp'),
                Selector::inNamespace('Psr\Http\Client'),
                Selector::inNamespace('Symfony\Contracts\HttpClient'),
            )
            ->because(
                'Outbound HTTP goes through Core\Support\Integration, which owns timeouts, '.
                'retries, secret redaction, correlation and call records for every provider '.
                '(ADR-0026). An adapter describes the call; it never performs it.'
            );
    }
}
