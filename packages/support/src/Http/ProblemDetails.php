<?php

declare(strict_types=1);

namespace Core\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

/**
 * RFC 7807 (application/problem+json) error responses — the single error format
 * of every Core API. Always carries the correlation id so a caller can quote it
 * when asking for support.
 */
final class ProblemDetails
{
    /**
     * @param array<string, mixed> $extra
     */
    public static function make(string $type, string $title, int $status, ?string $detail = null, array $extra = []): JsonResponse
    {
        $body = array_filter([
            'type' => 'https://errors.core-system.internal/'.$type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'correlation_id' => Context::get('correlation_id'),
        ], static fn ($value) => $value !== null);

        return new JsonResponse($body + $extra, $status, [
            'Content-Type' => 'application/problem+json',
        ]);
    }

    public static function unauthenticated(string $detail): JsonResponse
    {
        return self::make('unauthenticated', 'Unauthenticated', 401, $detail);
    }

    public static function forbidden(string $detail): JsonResponse
    {
        return self::make('forbidden', 'Forbidden', 403, $detail);
    }
}
