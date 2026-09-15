<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Port\OperatorTokenIssuer;

final class FakeOperatorTokenIssuer implements OperatorTokenIssuer
{
    /** @var list<array{operatorId: string, tokenName: string}> */
    public array $issued = [];

    public function issue(string $operatorId, string $tokenName): string
    {
        $this->issued[] = ['operatorId' => $operatorId, 'tokenName' => $tokenName];

        return 'fixed-operator-token-'.$operatorId;
    }
}
