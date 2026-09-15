<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class ReconciliationSummary
{
    public function __construct(
        public int $checked,
        public int $updated,
        public int $errors,
    ) {}
}
