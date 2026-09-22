<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Http;

use Core\Support\Http\HttpStatusClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The classification table that drives `WebhookRetryPolicy` and
 * `DeliverPaymentCallbackJob`'s `fail()` branch (ADR-0055). The
 * exhaustive-data-provider approach catches a regression where someone
 * changes one half without the other — every code that ends up in
 * the retry policy's `nextRetryAt()` decision passes through here.
 */
final class HttpStatusClassifierTest extends TestCase
{
    /**
     * The classifier is only consulted on the failure path — senders
     * return `success()` for 2xx and only build a `failure()` outcome
     * for non-2xx responses. So the table below covers the status
     * codes a `failure()` outcome can carry; 2xx/3xx are deliberately
     * excluded from the test (the sender never asks the classifier
     * about them).
     *
     * @return array<string, array{int|null, bool, bool}>
     */
    public static function classificationCases(): array
    {
        return [
            // 4xx: permanent, except 408 and 429.
            '400 Bad Request is permanent' => [400, true, false],
            '401 Unauthorized is permanent' => [401, true, false],
            '403 Forbidden is permanent' => [403, true, false],
            '404 Not Found is permanent' => [404, true, false],
            '405 Method Not Allowed is permanent' => [405, true, false],
            '410 Gone is permanent' => [410, true, false],
            '415 Unsupported Media Type is permanent' => [415, true, false],
            '422 Unprocessable Entity is permanent' => [422, true, false],
            '429 Too Many Requests is transient' => [429, false, true],
            '408 Request Timeout is transient' => [408, false, true],

            // 5xx: transient.
            '500 Internal Server Error is transient' => [500, false, true],
            '502 Bad Gateway is transient' => [502, false, true],
            '503 Service Unavailable is transient' => [503, false, true],
            '504 Gateway Timeout is transient' => [504, false, true],

            // Network: never reached the server, default to transient.
            'null (network failure) is transient' => [null, false, true],
        ];
    }

    #[Test]
    #[DataProvider('classificationCases')]
    public function classification_matches_expectations(?int $httpStatus, bool $expectedPermanent, bool $expectedTransient): void
    {
        self::assertSame(
            $expectedPermanent,
            HttpStatusClassifier::isPermanentFailure($httpStatus),
            "isPermanentFailure({$httpStatus}) expected {$expectedPermanent}",
        );
        self::assertSame(
            $expectedTransient,
            HttpStatusClassifier::isTransientFailure($httpStatus),
            "isTransientFailure({$httpStatus}) expected {$expectedTransient}",
        );
    }

    #[Test]
    public function permanent_and_transient_are_complementary(): void
    {
        foreach (self::classificationCases() as [$httpStatus]) {
            self::assertNotSame(
                HttpStatusClassifier::isPermanentFailure($httpStatus),
                HttpStatusClassifier::isTransientFailure($httpStatus),
                "Status {$httpStatus} cannot be both permanent and transient",
            );
        }
    }
}
