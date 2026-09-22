<?php

declare(strict_types=1);

namespace App\OpenApi;

use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response as OpenApiResponse;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Validation\ValidationException;

/**
 * Documents the `validation-failed` 422 problem+json shape produced by
 * `bootstrap/app.php`'s exception handler — `{type, title, status, detail,
 * correlation_id, errors}`, with `errors` being a `{field: [msg, ...]}`
 * map (Laravel's `ValidationException::errors()`).
 *
 * `ExceptionProblemResponseExtension` already covers the 22 domain
 * exceptions mapped in `ExceptionProblemMapping`, but `ValidationException`
 * is built-in to Laravel and Scramble's `ErrorResponsesExtension`
 * auto-attaches it to every route taking a `FormRequest`. Without this
 * extension, those routes' 422 schema was empty `properties: []` and the
 * `errors` field (the part a caller actually consumes to render inline
 * form errors) was invisible.
 *
 * Known same-code- winner-takes-all collision is documented on
 * `ExceptionProblemResponseExtension` and applies here the same way — for
 * routes that ALSO `@throws` a 422 business exception, that one wins.
 * Routes using `FormRequest` without a colliding `@throws` get this shape.
 */
final class ValidationExceptionResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && is_a($type->name, ValidationException::class, true);
    }

    public function toResponse(Type $type): ?OpenApiResponse
    {
        if (! $type instanceof ObjectType) {
            return null;
        }

        $typeProp = new OpenApiTypes\StringType;
        $typeProp->setDescription('A URI identifying this specific error condition.');
        $typeProp->example('https://errors.core-system.internal/validation-failed');

        $titleProp = new OpenApiTypes\StringType;
        $titleProp->setDescription('A short, human-readable summary of the error condition.');
        $titleProp->example('The request payload is invalid');

        $statusProp = new OpenApiTypes\IntegerType;
        $statusProp->setDescription('The HTTP status code, repeated in the body for a caller that only inspects the payload.');
        $statusProp->const(422);

        $detailProp = new OpenApiTypes\StringType;
        $detailProp->setDescription('A human-readable explanation specific to this occurrence of the error.');
        $detailProp->example('One or more fields failed validation.');

        $correlationIdProp = new OpenApiTypes\StringType;
        $correlationIdProp->setDescription('Quote this when contacting support about this specific failure.');

        $fieldProp = new OpenApiTypes\StringType;
        $errorsValue = new OpenApiTypes\ArrayType;
        $errorsValue->items = $fieldProp;

        $errorsMap = new OpenApiTypes\ObjectType;
        $errorsMap->additionalProperties = $errorsValue;
        $errorsMap->setDescription('Per-field error messages, keyed by field name. The same shape as `Illuminate\\Validation\\Validator::errors()`.');

        $schema = new OpenApiTypes\ObjectType;
        $schema->addProperty('type', $typeProp);
        $schema->addProperty('title', $titleProp);
        $schema->addProperty('status', $statusProp);
        $schema->addProperty('detail', $detailProp);
        $schema->addProperty('correlation_id', $correlationIdProp);
        $schema->addProperty('errors', $errorsMap);
        $schema->setRequired(['type', 'title', 'status']);

        /** @var OpenApiResponse $response */
        $response = OpenApiResponse::make(422);
        $response->setDescription('The request payload is invalid');

        /** @var Schema $content */
        $content = Schema::fromType($schema);
        $response->setContent('application/problem+json', $content);

        return $response;
    }
}
