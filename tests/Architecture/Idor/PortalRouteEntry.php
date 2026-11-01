<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

/**
 * One line of the portal IDOR manifest: a portal route carrying an
 * identifier, named by its Laravel route name (the URI is read back from
 * the routing table, so the two never drift).
 */
final readonly class PortalRouteEntry
{
    private function __construct(
        public string $route,
        public PortalResourceNature $resource,
        public PortalCoverage $coverage,
        /** The answer for a resource that is not the caller's. */
        public ?PortalOutcome $expected,
        /** Also run the positive control: the caller's own resource must be answered. */
        public bool $control,
        /** Why this entry is not an isolation case. */
        public ?string $reason,
    ) {}

    public static function isolated(
        string $route,
        PortalResourceNature $resource,
        PortalOutcome $expected,
        bool $control = false,
    ): self {
        return new self($route, $resource, PortalCoverage::Isolated, $expected, $control, null);
    }

    public static function oneTimeLink(string $route, PortalResourceNature $resource, string $reason): self
    {
        return new self($route, $resource, PortalCoverage::OneTimeLink, PortalOutcome::Gone, false, $reason);
    }

    public static function publicDoor(string $route, PortalResourceNature $resource, string $reason): self
    {
        return new self($route, $resource, PortalCoverage::PublicDoor, null, false, $reason);
    }

    public function describe(): string
    {
        return sprintf('%s (%s)', $this->route, $this->resource->value);
    }
}
