<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0049 — the API documentation carries **only the external
 * integrations**.
 *
 * A project integrating the Core calls the `/v1` plane with an API key: the
 * payments, the notifications, the webhooks, the customers, the catalogue,
 * the subscriptions, the invoices, the wallets. The operator plane
 * (`/v1/admin/*`, `/v1/operators/*`) belongs to the console — it is the
 * application's own usage, not a third-party integration, and an integrator
 * reading the API documentation has no reason to see it.
 *
 * ADR-0049 separated the two documents. It also stated the separation was
 * "verified" — but nothing kept it verified: the exclusion lived in a
 * configuration file, and one careless edit to it would have leaked the
 * operator plane into the integration document. This test is that
 * guarantee.
 *
 * The snapshots are read from disk — exactly what the CI job publishes and
 * what an integrator reads.
 */
#[Group('arch')]
final class OpenApiSurfaceTest extends TestCase
{
    /** The prefixes the operator plane owns. Never part of the API doc. */
    private const OPERATOR_PREFIXES = ['/v1/admin', '/v1/operators'];

    #[Test]
    public function the_api_documentation_carries_no_operator_path(): void
    {
        foreach ($this->pathsOf('openapi/v1.json') as $path) {
            self::assertStringStartsNotWith(
                '/admin',
                $path,
                "The API documentation exposes the operator plane: {$path} (ADR-0049).",
            );
            self::assertStringStartsNotWith(
                '/operators',
                $path,
                "The API documentation exposes the operator plane: {$path} (ADR-0049).",
            );
        }
    }

    #[Test]
    public function every_documented_path_belongs_to_the_project_plane(): void
    {
        $documented = $this->pathsOf('openapi/v1.json');
        $live = $this->liveProjectPaths();

        self::assertNotEmpty($documented, 'The API documentation is empty — nothing to check.');

        foreach ($documented as $path) {
            self::assertArrayHasKey(
                $path,
                $live,
                "The API documentation describes a route that does not exist: {$path} (ADR-0049).",
            );
        }
    }

    #[Test]
    public function the_admin_documentation_carries_only_the_operator_plane(): void
    {
        $paths = $this->pathsOf('openapi/admin.json');

        self::assertNotEmpty($paths, 'The admin documentation is empty.');

        foreach ($paths as $path) {
            $operator = false;

            foreach (self::OPERATOR_PREFIXES as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $operator = true;
                }
            }

            self::assertTrue(
                $operator,
                "The admin documentation describes a route outside the operator plane: {$path} (ADR-0049).",
            );
        }
    }

    #[Test]
    public function the_two_documents_never_overlap(): void
    {
        $shared = array_intersect(
            $this->pathsOf('openapi/v1.json'),
            $this->pathsOf('openapi/admin.json'),
        );

        self::assertSame([], array_values($shared), 'A path is documented twice (ADR-0049).');
    }

    #[Test]
    public function each_document_says_which_audience_it_serves(): void
    {
        $api = $this->descriptionOf('openapi/v1.json');
        $admin = $this->descriptionOf('openapi/admin.json');

        self::assertNotSame('', $api, 'The API documentation has no description.');
        self::assertNotSame('', $admin, 'The admin documentation has no description.');

        // ADR-0049: the admin document must say, in as many words, that it
        // is not a third-party integration surface.
        self::assertStringContainsStringIgnoringCase(
            'integration',
            $admin,
            'The admin documentation does not say it is not an integration surface (ADR-0049).',
        );
    }

    /**
     * The paths a document describes, without the API version prefix.
     *
     * @return list<string>
     */
    private function pathsOf(string $file): array
    {
        $path = base_path($file);

        self::assertFileExists($path, "The OpenAPI snapshot is missing: {$file}.");

        /** @var array<string, mixed> $document */
        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $paths */
        $paths = is_array($document['paths'] ?? null) ? $document['paths'] : [];

        return array_map(strval(...), array_keys($paths));
    }

    private function descriptionOf(string $file): string
    {
        $path = base_path($file);

        self::assertFileExists($path, "The OpenAPI snapshot is missing: {$file}.");

        /** @var array<string, mixed> $document */
        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $info = $document['info'] ?? null;
        $description = is_array($info) ? ($info['description'] ?? '') : '';

        return is_string($description) ? $description : '';
    }

    /**
     * The live routes of the project plane, under the same shape the
     * document uses: no `/v1` prefix.
     *
     * @return array<string, true>
     */
    private function liveProjectPaths(): array
    {
        $paths = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'v1/')) {
                continue;
            }

            $relative = '/'.substr($uri, 3);

            foreach (self::OPERATOR_PREFIXES as $prefix) {
                if (str_starts_with('/'.$uri, $prefix)) {
                    continue 2;
                }
            }

            $paths[$relative] = true;
        }

        return $paths;
    }
}
