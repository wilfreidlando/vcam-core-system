<?php

declare(strict_types=1);

use Core\Parties\Domain\Model\Party;

it('creates a lazily-materialised party with kyc_status none', function (): void {
    $party = Party::create('party-1', 'project-1', 'ext-ref-1', 'provider', ['tier' => 'gold']);

    expect($party->id)->toBe('party-1')
        ->and($party->projectId)->toBe('project-1')
        ->and($party->externalRef)->toBe('ext-ref-1')
        ->and($party->type())->toBe('provider')
        ->and($party->kycStatus())->toBe('none')
        ->and($party->metadata())->toBe(['tier' => 'gold']);
});

it('rejects a blank external_ref', function (): void {
    Party::create('party-1', 'project-1', '   ', 'provider');
})->throws(InvalidArgumentException::class, 'external_ref cannot be blank');

it('rejects a blank type', function (): void {
    Party::create('party-1', 'project-1', 'ext-ref-1', '  ');
})->throws(InvalidArgumentException::class, 'type cannot be blank');
