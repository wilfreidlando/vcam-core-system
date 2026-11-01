<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

/**
 * One line of the IDOR manifest: a console route carrying an identifier,
 * named by its Laravel route name (the URI is read back from the routing
 * table, so the two never drift).
 */
final readonly class IdorRouteEntry
{
    private function __construct(
        public string $route,
        public ResourceNature $resource,
        public IdorCoverage $coverage,
        /** The answer for B's resource addressed under B's own project/organization. */
        public ?ExpectedOutcome $expected,
        /**
         * The answer for B's child resource addressed under A's project or
         * organization (`/projects/{A}/api-keys/{key of B}`) — the IDOR the
         * parent check alone does not catch. Null when the route has no
         * child identifier, or when `$ownParentSkipped` says why.
         */
        public ?ExpectedOutcome $ownParent,
        public ?string $ownParentSkipped,
        /** Also run the positive control: A's own resource must not be "not found". */
        public bool $control,
        /** Why a platform-reserved entry is not an isolation case. */
        public ?string $reason,
    ) {}

    public static function isolated(
        string $route,
        ResourceNature $resource,
        ExpectedOutcome $expected,
        ?ExpectedOutcome $ownParent = null,
        bool $control = false,
        ?string $ownParentSkipped = null,
    ): self {
        return new self($route, $resource, IdorCoverage::Isolated, $expected, $ownParent, $ownParentSkipped, $control, null);
    }

    public static function platformReserved(string $route, ResourceNature $resource, string $reason): self
    {
        return new self($route, $resource, IdorCoverage::PlatformReserved, null, null, null, false, $reason);
    }

    public function describe(): string
    {
        return sprintf('%s (%s)', $this->route, $this->resource->value);
    }
}
