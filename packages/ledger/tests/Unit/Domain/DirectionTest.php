<?php

declare(strict_types=1);

use Core\Ledger\Domain\Model\Direction;

it('signs credit positive and debit negative', function (): void {
    expect(Direction::Credit->sign())->toBe(1)
        ->and(Direction::Debit->sign())->toBe(-1);
});
