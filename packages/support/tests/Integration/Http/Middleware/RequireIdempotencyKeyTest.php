<?php

declare(strict_types=1);

use Core\Support\Http\Middleware\RequireIdempotencyKey;
use Core\Support\Tests\Unit\Idempotency\Fakes\FixedIdempotencyScope;
use Core\Support\Tests\Unit\Idempotency\Fakes\InMemoryIdempotencyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * The middleware's own state machine, isolated from any real HTTP
 * kernel or cross-module route — `support` may not depend on any
 * module (identity, payment...), so this test proves the ADR-0025
 * logic in pure Contracts/port terms. The end-to-end wiring onto a
 * real monetary route is `Core\Payment`'s own concern to test (it owns
 * the only route that carries this middleware today).
 */
function middleware(?InMemoryIdempotencyStore $store = null): RequireIdempotencyKey
{
    return new RequireIdempotencyKey($store ?? new InMemoryIdempotencyStore, new FixedIdempotencyScope);
}

/**
 * @return Closure(Request): Response
 */
function respondingWith(int $status, mixed $body): Closure
{
    return static fn (Request $request): Response => new JsonResponse($body, $status);
}

it('rejects a request with no Idempotency-Key header', function (): void {
    $next = respondingWith(200, ['ok' => true]);
    $called = false;
    $spy = function (Request $r) use ($next, &$called): Response {
        $called = true;

        return $next($r);
    };

    $response = middleware()->handle(Request::create('/x', 'POST'), $spy);

    expect($response->getStatusCode())->toBe(400)
        ->and($called)->toBeFalse();
});

it('locks an unknown key, calls next, and caches the response', function (): void {
    $store = new InMemoryIdempotencyStore;
    $request = Request::create('/x', 'POST', content: '{"a":1}');
    $request->headers->set(RequireIdempotencyKey::HEADER, 'key-1');
    $calls = 0;

    $response = middleware($store)->handle($request, function (Request $r) use (&$calls): Response {
        $calls++;

        return new JsonResponse(['result' => 'done'], 201);
    });

    expect($calls)->toBe(1)
        ->and($response->getStatusCode())->toBe(201);

    $record = $store->find('project-1', 'key-1');
    Assert::assertNotNull($record);
    expect($record->completed)->toBeTrue()
        ->and($record->responseStatus)->toBe(201);
});

it('replays the cached response for the same key and body without calling next again', function (): void {
    $store = new InMemoryIdempotencyStore;
    $mw = middleware($store);
    $request = Request::create('/x', 'POST', content: '{"a":1}');
    $request->headers->set(RequireIdempotencyKey::HEADER, 'key-2');
    $calls = 0;
    $next = function (Request $r) use (&$calls): Response {
        $calls++;

        return new JsonResponse(['result' => 'done'], 201);
    };

    $first = $mw->handle($request, $next);
    $second = $mw->handle($request, $next);

    expect($calls)->toBe(1, 'next() must run exactly once')
        ->and($second->getStatusCode())->toBe($first->getStatusCode())
        ->and($second->getContent())->toBe($first->getContent());
});

it('rejects a reused key with a different request body as a conflict', function (): void {
    $store = new InMemoryIdempotencyStore;
    $mw = middleware($store);
    $next = respondingWith(201, ['result' => 'done']);

    $first = Request::create('/x', 'POST', content: '{"a":1}');
    $first->headers->set(RequireIdempotencyKey::HEADER, 'key-3');
    $mw->handle($first, $next);

    $second = Request::create('/x', 'POST', content: '{"a":2}');
    $second->headers->set(RequireIdempotencyKey::HEADER, 'key-3');
    $response = $mw->handle($second, $next);

    expect($response->getStatusCode())->toBe(409);
});

it('rejects a request whose key is still locked (in flight)', function (): void {
    $store = new InMemoryIdempotencyStore;
    // Simulate another request currently holding the lock: reserved,
    // never completed.
    $store->lock('project-1', 'key-4', hash('sha256', '{}'));

    $request = Request::create('/x', 'POST', content: '{}');
    $request->headers->set(RequireIdempotencyKey::HEADER, 'key-4');
    $called = false;

    $response = middleware($store)->handle($request, function (Request $r) use (&$called): Response {
        $called = true;

        return new JsonResponse([], 200);
    });

    expect($response->getStatusCode())->toBe(409)
        ->and($called)->toBeFalse();
});

it('scopes keys by IdempotencyScope: the same key under a different scope is independent', function (): void {
    $store = new InMemoryIdempotencyStore;
    $next = respondingWith(201, ['result' => 'done']);

    $request = Request::create('/x', 'POST', content: '{}');
    $request->headers->set(RequireIdempotencyKey::HEADER, 'shared-key');

    $mwProjectOne = new RequireIdempotencyKey($store, new FixedIdempotencyScope('project-1'));
    $mwProjectTwo = new RequireIdempotencyKey($store, new FixedIdempotencyScope('project-2'));

    $calls = 0;
    $counting = function (Request $r) use (&$calls, $next): Response {
        $calls++;

        return $next($r);
    };

    $mwProjectOne->handle($request, $counting);
    $mwProjectTwo->handle($request, $counting);

    expect($calls)->toBe(2, 'two different projects must never collide on the same key');
});
