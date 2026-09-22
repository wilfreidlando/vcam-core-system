<?php

declare(strict_types=1);

namespace App\OpenApi;

use Core\Support\Http\Middleware\RequireIdempotencyKey;
use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Documents the `Idempotency-Key` header (G7, ADR-0025) on every route
 * that carries the `idempotency` middleware — currently the six monetary
 * and state-mutating POSTs under `/v1/payments/*` and `/v1/notifications/*`.
 *
 * Without this, `/docs/api` lists `POST /v1/payments` (and the rest) with
 * no header documentation at all: there is no static-analysis path
 * Scramble can walk from a FormRequest to "this controller is gated by
 * `RequireIdempotencyKey`", so it never infers the header on its own.
 *
 * The 400 (`idempotency-key-required`) and 409 (`idempotency-key-conflict`)
 * responses — produced by the same middleware — are attached here for
 * the same reason: the middleware's three failure modes are invisible to
 * Scramble's static analysis unless something publishes them. The 409
 * case is split into two distinct titles in the live response (in-flight
 * vs. body-mismatch) — collapsed to one here for readability, both share
 * the `idempotency-key-conflict` problem type and the same body shape.
 */
final class IdempotencyHeaderExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! in_array('idempotency', $routeInfo->route->middleware(), true)) {
            return;
        }

        $operation->addParameters([$this->buildHeaderParameter()]);
        $operation->addResponse($this->buildProblemResponse(
            400,
            'idempotency-key-required',
            'Idempotency-Key header is required',
            'This route mutates state and must be called with an Idempotency-Key header.',
        ));
        $operation->addResponse($this->buildProblemResponse(
            409,
            'idempotency-key-conflict',
            'Idempotency-Key was reused',
            'Either a concurrent request with this Idempotency-Key is still in flight, or the key was reused with a different request body.',
        ));
    }

    private function buildHeaderParameter(): Parameter
    {
        $schema = new OpenApiTypes\StringType;
        $schema->setMax(255);

        $parameter = Parameter::make(RequireIdempotencyKey::HEADER, 'header');
        $parameter->required = true;
        $parameter->description = 'Caller-supplied unique key used for both the HTTP-level response cache and the use case\'s own duplicate-effect guard. Same key + same body = cached response, never a second effect. Same key + different body = 409. See ADR-0025 (idempotency design) and G7 (mandatory on every monetary mutation route).';
        $parameter->example('01HMZ9XK3P-9XK3P-9XK3P');
        $built = Schema::fromType($schema);
        assert($built instanceof Schema);
        $parameter->setSchema($built);

        return $parameter;
    }

    private function buildProblemResponse(int $status, string $type, string $title, string $detail): Response
    {
        $typeProp = new OpenApiTypes\StringType;
        $typeProp->setDescription('A URI identifying this specific error condition.');
        $typeProp->example('https://errors.core-system.internal/'.$type);

        $titleProp = new OpenApiTypes\StringType;
        $titleProp->setDescription('A short, human-readable summary of the error condition.');
        $titleProp->example($title);

        $statusProp = new OpenApiTypes\IntegerType;
        $statusProp->setDescription('The HTTP status code, repeated in the body for a caller that only inspects the payload.');
        $statusProp->const($status);

        $detailProp = new OpenApiTypes\StringType;
        $detailProp->setDescription('A human-readable explanation specific to this occurrence of the error.');
        $detailProp->example($detail);

        $correlationIdProp = new OpenApiTypes\StringType;
        $correlationIdProp->setDescription('Quote this when contacting support about this specific failure.');

        $schema = new OpenApiTypes\ObjectType;
        $schema->addProperty('type', $typeProp);
        $schema->addProperty('title', $titleProp);
        $schema->addProperty('status', $statusProp);
        $schema->addProperty('detail', $detailProp);
        $schema->addProperty('correlation_id', $correlationIdProp);
        $schema->setRequired(['type', 'title', 'status']);

        /** @var Response $response */
        $response = Response::make($status);
        $response->setDescription($title);
        /** @var Schema $content */
        $content = Schema::fromType($schema);
        $response->setContent('application/problem+json', $content);

        return $response;
    }
}
