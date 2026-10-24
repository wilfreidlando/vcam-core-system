<?php

declare(strict_types=1);

use Core\Identity\Domain\Exception\InvalidApiKeySettings;
use Core\Identity\Domain\Model\CidrBlock;

it('matches the addresses of its block, IPv4 and IPv6', function (string $cidr, string $ip, bool $expected): void {
    expect(CidrBlock::parse($cidr)->contains($ip))->toBe($expected);
})->with([
    ['203.0.113.0/24', '203.0.113.255', true],
    ['203.0.113.0/24', '203.0.114.0', false],
    ['10.0.0.0/8', '10.255.0.1', true],
    ['0.0.0.0/0', '192.0.2.1', true],
    ['192.0.2.10', '192.0.2.10', true],
    ['192.0.2.10', '192.0.2.11', false],
    ['203.0.113.0/27', '203.0.113.31', true],
    ['203.0.113.0/27', '203.0.113.32', false],
    ['2001:db8::/32', '2001:db8:ffff::1', true],
    ['2001:db8::/32', '2001:db9::1', false],
    // An IPv4 block never matches an IPv6 address, and the other way round.
    ['0.0.0.0/0', '::1', false],
    ['::/0', '127.0.0.1', false],
    ['203.0.113.0/24', 'not-an-ip', false],
    // IPv4-mapped IPv6, as a dual-stack listener reports IPv4 clients.
    ['203.0.113.0/24', '::ffff:203.0.113.5', true],
    ['203.0.113.0/24', '::ffff:198.51.100.5', false],
    ['::ffff:203.0.113.0/120', '203.0.113.9', true],
]);

it('stores the network address, whatever host bits were typed', function (): void {
    expect(CidrBlock::parse(' 203.0.113.77/24 ')->toString())->toBe('203.0.113.0/24')
        ->and(CidrBlock::parse('2001:DB8::1')->toString())->toBe('2001:db8::1/128')
        ->and(CidrBlock::parse('::ffff:203.0.113.0/120')->toString())->toBe('203.0.113.0/24');
});

it('refuses what is not a block', function (string $value): void {
    CidrBlock::parse($value);
})->with(['::ffff:0.0.0.0/95', '10.0.0.0/33', '2001:db8::/129', '10.0.0.0/-1', '10.0.0/8', 'example.org', '', '10.0.0.0/8/1', '10.0.0.0/ 8'])
    ->throws(InvalidApiKeySettings::class);
