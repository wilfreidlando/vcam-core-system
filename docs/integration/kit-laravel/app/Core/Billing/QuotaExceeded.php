<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

/** The tenant's plan does not allow this unit (HTTP 429 from the Core). */
final class QuotaExceeded extends RuntimeException
{
    public function __construct(public readonly string $meter, public readonly int $used, public readonly ?int $limit)
    {
        parent::__construct("Quota exceeded for {$meter}: {$used}/".($limit ?? '∞'));
    }
}
