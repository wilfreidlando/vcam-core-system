<?php

declare(strict_types=1);

use Core\Support\Observability\TraceContext;

/**
 * ADR-0059 — inbound identifiers are validated before reaching any log.
 */
it('keeps a well-formed inbound correlation id', function (): void {
    expect(TraceContext::sanitizeCorrelationId('order-1234_ABC.9'))->toBe('order-1234_ABC.9')
        ->and(TraceContext::sanitizeCorrelationId('0b6e1d6c-6c39-4c0d-9b7f-5f3f6d2d7c11'))->toBe('0b6e1d6c-6c39-4c0d-9b7f-5f3f6d2d7c11');
});

it('replaces an inbound correlation id that could forge log lines', function (string $hostile): void {
    $sanitized = TraceContext::sanitizeCorrelationId($hostile);

    expect($sanitized)->not->toBe($hostile)
        ->and($sanitized)->toMatch('/^[0-9a-f-]{36}$/');
})->with([
    'newline injection' => ["abc\n{\"level\":\"critical\"}"],
    'too long' => [str_repeat('a', 65)],
    'empty' => [''],
    'spaces' => ['a b'],
]);

it('parses a valid W3C traceparent and rejects invalid ones', function (): void {
    expect(TraceContext::parseTraceparent('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'))
        ->toBe(['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'parent_span_id' => '00f067aa0ba902b7'])
        ->and(TraceContext::parseTraceparent('00-00000000000000000000000000000000-00f067aa0ba902b7-01'))->toBeNull()
        ->and(TraceContext::parseTraceparent('garbage'))->toBeNull()
        ->and(TraceContext::parseTraceparent(null))->toBeNull();
});
