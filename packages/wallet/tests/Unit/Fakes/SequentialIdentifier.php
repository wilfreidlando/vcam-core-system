<?php

declare(strict_types=1);

namespace Core\Wallet\Tests\Unit\Fakes;

use Core\Support\Identifier;

final class SequentialIdentifier implements Identifier
{
    private int $next = 1;

    public function generate(): string
    {
        return 'id-'.$this->next++;
    }
}
