<?php

declare(strict_types=1);

namespace App\OpenApi;

use Core\Support\Http\ProblemDetails;
use Dedoc\Scramble\Infer\Extensions\Event\StaticMethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\StaticMethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Http\JsonResponse;

/**
 * Every scope/permission check in this Core returns
 * `ProblemDetails::forbidden(...)`/`unauthenticated(...)` — inline, in the
 * controller method body, never as a thrown-and-caught exception — which
 * is why `ExceptionProblemResponseExtension` (the `@throws`-driven
 * mechanism) never applies to them. Left to Scramble's own inference,
 * `ProblemDetails::make()`'s body (`array_filter([...], ...) + $extra`)
 * degrades to a bare untyped `array` — rendered in the doc as
 * `{type: array, items: {}}`, on nearly every endpoint in the API, since
 * almost every controller gates on a scope this way. This extension
 * intercepts the three `ProblemDetails` static calls directly and returns
 * the precise `{type, title, status, detail, correlation_id}` shape
 * instead, mirroring `ProblemDetails::make()`'s own body construction
 * exactly — including reproducing the `https://errors.core-system.internal/`
 * prefix here, since Scramble's inference can't trace that string
 * concatenation from a literal argument on its own.
 *
 * @see ExceptionProblemResponseExtension for the exception-thrown
 *      counterpart covering the 22 domain exceptions that DO cross a
 *      `throw` boundary (whether caught here or left for
 *      `bootstrap/app.php`'s handler).
 */
final class ProblemDetailsStaticMethodReturnTypeExtension implements StaticMethodReturnTypeExtension
{
    public function shouldHandle(string $name): bool
    {
        return $name === ProblemDetails::class;
    }

    public function getStaticMethodReturnType(StaticMethodCallEvent $event): ?Type
    {
        [$typeArg, $titleArg, $statusArg] = match ($event->name) {
            'make' => [$event->getArg('type', 0), $event->getArg('title', 1), $event->getArg('status', 2)],
            'unauthenticated' => [new LiteralStringType('unauthenticated'), new LiteralStringType('Unauthenticated'), new LiteralIntegerType(401)],
            'forbidden' => [new LiteralStringType('forbidden'), new LiteralStringType('Forbidden'), new LiteralIntegerType(403)],
            default => [null, null, null],
        };

        if ($typeArg === null || $titleArg === null || $statusArg === null) {
            return null;
        }

        $body = new KeyedArrayType([
            new ArrayItemType_('type', $this->toTypeUri($typeArg)),
            new ArrayItemType_('title', $titleArg instanceof LiteralStringType ? $titleArg : new StringType),
            new ArrayItemType_('status', $statusArg instanceof LiteralIntegerType ? $statusArg : new IntegerType),
            new ArrayItemType_('detail', new StringType, isOptional: true),
            new ArrayItemType_('correlation_id', new StringType, isOptional: true),
        ]);

        // A literal `Content-Type` header entry is specially recognised by
        // Scramble's own `ResponseTypeToSchema::handleContentTypeHeader()`
        // — it rewrites the response's media type from the `application/json`
        // it'd otherwise assume, matching the header `ProblemDetails::make()`
        // actually sends at runtime.
        $headers = new KeyedArrayType([
            new ArrayItemType_('Content-Type', new LiteralStringType('application/problem+json')),
        ]);

        return new Generic(JsonResponse::class, [
            $body,
            $statusArg,
            $headers,
        ]);
    }

    /**
     * `ProblemDetails::make()` stores `'https://errors.core-system.internal/'.$type`,
     * never the bare slug — reproduced here so a documented example
     * matches what the endpoint actually returns.
     */
    private function toTypeUri(Type $typeArg): Type
    {
        if (! $typeArg instanceof LiteralStringType) {
            return new StringType;
        }

        return new LiteralStringType('https://errors.core-system.internal/'.$typeArg->value);
    }
}
