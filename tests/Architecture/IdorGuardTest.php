<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\Idor\ConsoleRouteManifest;
use Tests\Architecture\Idor\IdorCoverage;
use Tests\Architecture\Idor\IdorSector;
use Tests\Architecture\Idor\ResourceNature;
use Tests\TestCase;

/**
 * G15 — the guard that keeps the IDOR manifest honest (ADR-0081 S-01/S-04,
 * ADR-0069 §Sécurité).
 *
 * `ConsoleIdorTest` generates one isolation case per manifest entry; that is
 * worth something only if the manifest covers **every** console route that
 * carries an identifier, today and after the next route lands. So the
 * manifest is checked against the live routing table, and an entry that is
 * not an isolation case must say why.
 */
#[Group('arch')]
final class IdorGuardTest extends TestCase
{
    /** Below this, the manifest is suspiciously small for this console. */
    private const MINIMUM_ISOLATED_ENTRIES = 40;

    #[Test]
    public function every_console_route_carrying_an_identifier_is_in_the_manifest(): void
    {
        $listed = array_flip(array_map(static fn ($entry): string => $entry->route, ConsoleRouteManifest::entries()));
        $missing = [];

        foreach (ConsoleRouteManifest::discoverConsoleRoutesWithIdentifier() as $name => $route) {
            if (! isset($listed[$name])) {
                $missing[] = sprintf(
                    '%s /%s (route "%s", plane %s, identifier %s)',
                    implode('|', self::strings($route->methods())),
                    $route->uri(),
                    $name,
                    ConsoleRouteManifest::planeOf($route)->value,
                    implode(', ', self::strings($route->parameterNames())),
                );
            }
        }

        self::assertSame([], $missing, sprintf(
            "G15 — these console routes carry an identifier but are not in the IDOR manifest:\n  - %s\n\n"
            ."Add each one to tests/Architecture/Idor/ConsoleRouteManifest.php with the answer it must give an\n"
            ."organization account for a resource of another organization (ADR-0069 §Sécurité, ADR-0081 S-04).\n"
            .'A route that is not listed is a route whose tenant isolation nobody proved.',
            implode("\n  - ", $missing),
        ));
    }

    #[Test]
    public function the_manifest_lists_only_existing_console_routes_and_each_once(): void
    {
        $routes = ConsoleRouteManifest::discoverConsoleRoutesWithIdentifier();
        $problems = [];
        $seen = [];

        foreach (ConsoleRouteManifest::entries() as $entry) {
            if (! isset($routes[$entry->route])) {
                $problems[] = $entry->route.' is not a console route with an identifier (any more)';
            }

            if (isset($seen[$entry->route])) {
                $problems[] = $entry->route.' is listed twice';
            }

            $seen[$entry->route] = true;
        }

        self::assertSame([], $problems, "G15 — the IDOR manifest reports a coverage the application does not have:\n  - ".implode("\n  - ", $problems));
    }

    #[Test]
    public function a_nested_route_is_also_checked_with_a_foreign_child_under_the_callers_own_parent(): void
    {
        $routes = ConsoleRouteManifest::discoverConsoleRoutesWithIdentifier();
        $unchecked = [];

        foreach (ConsoleRouteManifest::withCoverage(IdorCoverage::Isolated) as $entry) {
            $route = $routes[$entry->route] ?? null;

            if (! $route instanceof Route) {
                continue;
            }

            $parameters = self::strings($route->parameterNames());
            $nested = array_intersect($parameters, ConsoleRouteManifest::PARENT_PARAMETERS) !== []
                && array_diff($parameters, ConsoleRouteManifest::PARENT_PARAMETERS) !== [];

            if ($nested && $entry->ownParent === null && mb_strlen(trim((string) $entry->ownParentSkipped)) < 40) {
                $unchecked[] = $entry->route;
            }
        }

        self::assertSame([], $unchecked, sprintf(
            "G15 — these routes address a child resource under a project/organization, but do not declare the answer\n"
            ."for another organization's child under the caller's own parent (`ownParent:`), nor why it does not apply\n"
            ."(`ownParentSkipped:`):\n  - %s",
            implode("\n  - ", $unchecked),
        ));
    }

    #[Test]
    public function every_platform_reserved_entry_says_why_it_is_not_an_isolation_case(): void
    {
        $unjustified = [];

        foreach (ConsoleRouteManifest::withCoverage(IdorCoverage::PlatformReserved) as $entry) {
            if (mb_strlen(trim((string) $entry->reason)) < 80) {
                $unjustified[] = $entry->route;
            }
        }

        self::assertSame([], $unjustified, "G15 — these platform-reserved entries do not say why they are not isolation cases:\n  - ".implode("\n  - ", $unjustified));
    }

    #[Test]
    public function every_resource_nature_under_isolation_has_a_positive_control(): void
    {
        $natures = [];
        $controlled = [];

        foreach (ConsoleRouteManifest::withCoverage(IdorCoverage::Isolated) as $entry) {
            $natures[$entry->resource->value] = true;

            if ($entry->control) {
                $controlled[$entry->resource->value] = true;
            }
        }

        self::assertSame([], array_keys(array_diff_key($natures, $controlled)), sprintf(
            "G15 — these resource natures have no positive control: nothing proves their routes answer their own\n"
            .'organization, so a route that answers 404 to everybody would pass the IDOR case. Known natures: %s',
            implode(', ', array_map(static fn (ResourceNature $nature): string => $nature->value, ResourceNature::cases())),
        ));
    }

    #[Test]
    public function the_manifest_covers_both_planes_and_is_not_vacuous(): void
    {
        $isolated = count(ConsoleRouteManifest::withCoverage(IdorCoverage::Isolated));
        self::assertGreaterThanOrEqual(self::MINIMUM_ISOLATED_ENTRIES, $isolated, sprintf(
            'G15 — the IDOR manifest exercises only %d routes; the console carries far more.',
            $isolated,
        ));

        $planes = [];
        foreach (ConsoleRouteManifest::discoverConsoleRoutesWithIdentifier() as $route) {
            $planes[ConsoleRouteManifest::planeOf($route)->value] = true;
        }

        self::assertArrayHasKey('web', $planes, 'G15 — no web console route with an identifier found: the discovery is broken.');
        self::assertArrayHasKey('json', $planes, 'G15 — no JSON console route with an identifier found: the discovery is broken.');
    }

    /**
     * The sector map is what lets a sector be tested alone: the IDOR suite
     * is eighty seconds for the whole console, and a few seconds for one
     * bounded context. That only works if the map covers every route and
     * names no sector that owns nothing — a route nobody claimed would
     * silently stop being checked by anyone.
     */
    #[Test]
    public function every_console_route_belongs_to_a_sector_and_every_sector_owns_a_route(): void
    {
        $unmapped = [];
        $owned = [];

        foreach (ConsoleRouteManifest::entries() as $entry) {
            $sector = IdorSector::of($entry->route);

            if ($sector === 'unmapped') {
                $unmapped[] = $entry->route;

                continue;
            }

            $owned[$sector] = true;
        }

        self::assertSame([], $unmapped, sprintf(
            "G15 — these console routes belong to no sector, so no sector run covers them:\n  - %s\n\n"
            .'Add them to the map in tests/Architecture/Idor/IdorSector.php.',
            implode("\n  - ", $unmapped),
        ));

        $empty = array_values(array_diff(IdorSector::sectors(), array_keys($owned)));

        self::assertSame([], $empty, sprintf(
            "G15 — these sectors own no console route: they would run an empty selection:\n  - %s",
            implode("\n  - ", $empty),
        ));
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }
}
