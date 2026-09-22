<?php

declare(strict_types=1);

namespace App\OpenApi;

use App\Http\ExceptionProblemMapping;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * Documents the RFC 7807 problem+json shape for every exception listed
 * in `ExceptionProblemMapping` — the same table `bootstrap/app.php`'s
 * exception handler consumes, so the two can never silently drift apart.
 *
 * Scramble discovers WHICH exception types a route can throw from
 * `@throws` PHPDoc tags on the controller method it routes to (its
 * `PhpDocHandler` reads them into the method's inferred `exceptions`
 * list) — it does not trace across an injected `Contracts` interface
 * into whichever concrete use case actually gets bound, since it can't
 * know that statically. A controller's `@throws SomeDomainException` is
 * what makes this extension fire for that route at all; without it, the
 * route falls back to the generic 422/404 Scramble already infers from
 * validation rules / route-model binding. Confirmed empirically this
 * only works on the exact method the route points at — a `@throws` tag
 * on a shared parent's method is never picked up through a
 * `parent::__invoke()` call, so it must be repeated on every concrete
 * override.
 *
 * Known vendor limitation, confirmed by reading
 * `ResponseExtension::collectInferredResponses()`: Scramble documents at
 * most ONE response schema per HTTP status code per route. A route
 * validated by a `FormRequest` always gets Laravel's own
 * `ValidationException` auto-attached at 422
 * (`ErrorResponsesExtension::attachCustomRequestExceptions()`), and that
 * one is registered as a named component `Reference` — which always
 * wins the same-status-code collision over a `@throws`-documented
 * business exception mapped here to 422 (`UnsupportedCurrency`,
 * `NoProviderForChannelException`, …), because references are
 * concatenated after merged plain responses and the final assembly is a
 * last-write-wins array keyed by status code. Non-colliding statuses
 * (404, 409, 502, 503) are unaffected and render correctly. Not worked
 * around here — doing so would mean monkey-patching vendor merge logic
 * for a display-only gap; the `@throws` tags stay for their own
 * documentation value regardless.
 */
final class ExceptionProblemResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && ExceptionProblemMapping::resolve($type->name) !== null;
    }

    public function toResponse(Type $type): ?Response
    {
        if (! $type instanceof ObjectType) {
            return null;
        }

        $mapped = ExceptionProblemMapping::resolve($type->name);
        if ($mapped === null) {
            return null;
        }

        // Built as separate statements, not chained — the vendor Type
        // builder methods return $this without an explicit return-type
        // declaration, so PHPStan (level 10) sees a bare `mixed` the
        // moment a chained call's return value is itself used; calling
        // each mutator as its own statement, and never using its return
        // value, sidesteps that without a suppression comment on every line.
        $typeProp = new OpenApiTypes\StringType;
        $typeProp->setDescription('A URI identifying this specific error condition.');
        $typeProp->example('https://errors.core-system.internal/'.$mapped['type']);

        $titleProp = new OpenApiTypes\StringType;
        $titleProp->setDescription('A short, human-readable summary of the error condition.');
        $titleProp->example($mapped['title']);

        $statusProp = new OpenApiTypes\IntegerType;
        $statusProp->setDescription('The HTTP status code, repeated in the body for a caller that only inspects the payload.');
        $statusProp->const($mapped['status']);

        $detailProp = new OpenApiTypes\StringType;
        $detailProp->setDescription('A human-readable explanation specific to this occurrence of the error.');

        $correlationIdProp = new OpenApiTypes\StringType;
        $correlationIdProp->setDescription('Quote this when contacting support about this specific failure.');

        $schema = new OpenApiTypes\ObjectType;
        $schema->addProperty('type', $typeProp);
        $schema->addProperty('title', $titleProp);
        $schema->addProperty('status', $statusProp);
        $schema->addProperty('detail', $detailProp);
        $schema->addProperty('correlation_id', $correlationIdProp);
        $schema->setRequired(['type', 'title', 'status']);

        // `Response::make()` has the same untyped-return gap as the Type
        // builders above; an explicit cast is simpler here than another
        // round of statement-only calls, since this is the method's own
        // return value.
        /** @var Response $response */
        $response = Response::make($mapped['status']);
        $response->setDescription($mapped['title']);

        /** @var Schema $content */
        $content = Schema::fromType($schema);
        $response->setContent('application/problem+json', $content);

        return $response;
    }
}
