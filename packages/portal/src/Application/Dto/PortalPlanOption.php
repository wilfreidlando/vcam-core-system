<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

/**
 * ADR-0075 §3 — a plan the customer may move to, as the project published it.
 */
final readonly class PortalPlanOption
{
    /**
     * @param list<string> $highlights what the plan includes, ready to read
     */
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description,
        public int $priceMinor,
        public string $currency,
        public string $intervalUnit,
        public int $intervalCount,
        public array $highlights,
        public bool $isCurrent,
        public int $trialDays = 0,
    ) {}
}
