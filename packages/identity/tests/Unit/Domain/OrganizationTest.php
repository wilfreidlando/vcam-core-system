<?php

declare(strict_types=1);

use Core\Identity\Domain\Model\Organization;

it('is active when created', function (): void {
    $organization = Organization::create('org-1', 'Acme Corp');

    expect($organization->isActive())->toBeTrue()
        ->and($organization->status())->toBe('active')
        ->and($organization->name())->toBe('Acme Corp');
});

it('rejects a blank name', function (): void {
    Organization::create('org-1', '   ');
})->throws(InvalidArgumentException::class);
