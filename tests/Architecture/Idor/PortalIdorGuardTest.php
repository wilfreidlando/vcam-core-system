<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * G-10 — the guard that keeps the portal IDOR manifest honest (audit
 * 2026-10-29 finding #20, ADR-0081 S-01/S-04).
 *
 * `PortalIdorTest` generates one isolation case per manifest entry; that
 * is worth something only if the manifest covers **every** portal route
 * that carries an identifier, today and after the next route lands. So the
 * manifest is checked against the live routing table, and an entry that is
 * not an isolation case must say why.
 */
#[Group('arch')]
final class PortalIdorGuardTest extends TestCase
{
    /** Below this, the manifest is suspiciously small for this portal. */
    private const MINIMUM_ISOLATED_ENTRIES = 7;

    #[Test]
    public function every_portal_route_carrying_an_identifier_is_in_the_manifest(): void
    {
        $listed = array_flip(array_map(static fn (PortalRouteEntry $entry): string => $entry->route, PortalRouteManifest::entries()));
        $missing = [];

        foreach (PortalRouteManifest::discoverPortalRoutesWithIdentifier() as $name => $route) {
            if (! isset($listed[$name])) {
                $missing[] = sprintf(
                    '%s /%s (route "%s", identifier %s)',
                    implode('|', self::strings($route->methods())),
                    $route->uri(),
                    $name,
                    implode(', ', self::strings($route->parameterNames())),
                );
            }
        }

        self::assertSame([], $missing, sprintf(
            "G-10 — these portal routes carry an identifier but are not in the portal IDOR manifest:\n  - %s\n\n"
            ."Add each one to tests/Architecture/Idor/PortalRouteManifest.php with the answer it must give a customer\n"
            ."for a resource of another customer (ADR-0075 §3, ADR-0081 S-04).\n"
            .'A route that is not listed is a route whose customer isolation nobody proved.',
            implode("\n  - ", $missing),
        ));
    }

    #[Test]
    public function the_manifest_lists_only_existing_portal_routes_and_each_once(): void
    {
        $routes = PortalRouteManifest::discoverPortalRoutesWithIdentifier();
        $problems = [];
        $seen = [];

        foreach (PortalRouteManifest::entries() as $entry) {
            if (! isset($routes[$entry->route])) {
                $problems[] = $entry->route.' is not a portal route with an identifier (any more)';
            }

            if (isset($seen[$entry->route])) {
                $problems[] = $entry->route.' is listed twice';
            }

            $seen[$entry->route] = true;
        }

        self::assertSame([], $problems, "G-10 — the portal IDOR manifest reports a coverage the application does not have:\n  - ".implode("\n  - ", $problems));
    }

    #[Test]
    public function every_isolated_entry_has_a_positive_control(): void
    {
        $natures = [];
        $controlled = [];

        foreach (PortalRouteManifest::withCoverage(PortalCoverage::Isolated) as $entry) {
            $natures[$entry->resource->value] = true;

            if ($entry->control) {
                $controlled[$entry->resource->value] = true;
            }
        }

        self::assertSame([], array_keys(array_diff_key($natures, $controlled)), sprintf(
            "G-10 — these resource natures have no positive control: nothing proves their routes answer their own\n"
            .'customer, so a route that answers "not found" to everybody would pass the IDOR case. Known natures: %s',
            implode(', ', array_map(static fn (PortalResourceNature $nature): string => $nature->value, PortalResourceNature::cases())),
        ));
    }

    #[Test]
    public function every_entry_that_is_not_an_isolation_case_says_why(): void
    {
        $unjustified = [];

        foreach (PortalRouteManifest::entries() as $entry) {
            if ($entry->coverage === PortalCoverage::Isolated) {
                continue;
            }

            if (mb_strlen(trim((string) $entry->reason)) < 80) {
                $unjustified[] = $entry->route;
            }
        }

        self::assertSame([], $unjustified, "G-10 — these portal entries do not say why they are not isolation cases:\n  - ".implode("\n  - ", $unjustified));
    }

    #[Test]
    public function the_manifest_covers_both_portal_doors_and_is_not_vacuous(): void
    {
        $isolated = count(PortalRouteManifest::withCoverage(PortalCoverage::Isolated));
        self::assertGreaterThanOrEqual(self::MINIMUM_ISOLATED_ENTRIES, $isolated, sprintf(
            'G-10 — the portal IDOR manifest exercises only %d routes; the portal carries more.',
            $isolated,
        ));

        $behindASession = false;
        $public = false;

        foreach (PortalRouteManifest::discoverPortalRoutesWithIdentifier() as $route) {
            if (array_intersect(PortalRouteManifest::PORTAL_MIDDLEWARE, self::middlewareOf($route)) !== []) {
                $behindASession = true;

                continue;
            }

            $public = true;
        }

        self::assertTrue($behindASession, 'G-10 — no portal route behind a customer session found: the discovery is broken.');
        self::assertTrue($public, 'G-10 — no public portal door found: the discovery is broken.');
    }

    /**
     * The sector map is what lets a sector be tested alone: the portal
     * suite has its own fixtures, and a route nobody claimed would
     * silently stop being checked by anyone.
     */
    #[Test]
    public function every_portal_route_belongs_to_a_sector_and_every_sector_owns_a_route(): void
    {
        $unmapped = [];
        $owned = [];

        foreach (PortalRouteManifest::entries() as $entry) {
            $sector = IdorSector::of($entry->route);

            if ($sector === 'unmapped') {
                $unmapped[] = $entry->route;

                continue;
            }

            $owned[$sector] = true;
        }

        self::assertSame([], $unmapped, sprintf(
            "G-10 — these portal routes belong to no sector, so no sector run covers them:\n  - %s\n\n"
            .'Add them to the map in tests/Architecture/Idor/IdorSector.php.',
            implode("\n  - ", $unmapped),
        ));

        $empty = array_values(array_diff(IdorSector::portalSectors(), array_keys($owned)));

        self::assertSame([], $empty, sprintf(
            "G-10 — these sectors own no portal route: they would run an empty selection:\n  - %s",
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

    /**
     * @return list<string>
     */
    private static function middlewareOf(Route $route): array
    {
        return array_values(array_filter($route->gatherMiddleware(), is_string(...)));
    }
}
