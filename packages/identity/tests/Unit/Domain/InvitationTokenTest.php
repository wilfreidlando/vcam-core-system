<?php

declare(strict_types=1);

use Core\Identity\Domain\Model\InvitationToken;

$now = new DateTimeImmutable('2026-10-25T10:00:00Z');

it('is 32 random bytes, base64url-encoded without padding', function () use ($now): void {
    $token = InvitationToken::issue($now);
    $plain = $token->revealPlainText();

    expect($plain)->toBeString()
        ->and($plain)->toMatch('/^[A-Za-z0-9_-]{43}$/');

    $decoded = base64_decode(strtr((string) $plain, '-_', '+/'), true);
    expect($decoded)->not->toBeFalse()
        ->and(strlen((string) $decoded))->toBe(InvitationToken::ENTROPY_BYTES);
});

it('never issues the same token twice', function () use ($now): void {
    $tokens = [];
    for ($i = 0; $i < 50; $i++) {
        $tokens[] = InvitationToken::issue($now)->revealPlainText();
    }

    expect(array_unique($tokens))->toHaveCount(50);
});

it('keeps only the sha256 hash of the clear value', function () use ($now): void {
    $token = InvitationToken::issue($now);
    $plain = (string) $token->revealPlainText();

    expect($token->hash())->toBe(hash('sha256', $plain))
        ->and($token->hash())->not->toContain($plain)
        ->and(InvitationToken::hashOf($plain))->toBe($token->hash())
        ->and($token->matches($plain))->toBeTrue()
        ->and($token->matches($plain.'x'))->toBeFalse();
});

it('reveals the clear value once, then forgets it', function () use ($now): void {
    $token = InvitationToken::issue($now);

    expect($token->revealPlainText())->toBeString()
        ->and($token->revealPlainText())->toBeNull();
});

it('has no clear value when rebuilt from storage', function () use ($now): void {
    $restored = InvitationToken::restore(hash('sha256', 'x'), $now->modify('+1 hour'));

    expect($restored->revealPlainText())->toBeNull();
});

it('is valid for exactly 72 hours', function () use ($now): void {
    $token = InvitationToken::issue($now);

    expect($token->expiresAt())->toEqual($now->modify('+72 hours'))
        ->and($token->isUsable($now->modify('+71 hours 59 minutes')))->toBeTrue()
        ->and($token->isExpired($now->modify('+72 hours')))->toBeTrue()
        ->and($token->isUsable($now->modify('+72 hours')))->toBeFalse();
});

it('is single use: once accepted it is no longer usable, and the first acceptance time sticks', function () use ($now): void {
    $token = InvitationToken::issue($now);

    $token->accept($now->modify('+1 hour'));
    $token->accept($now->modify('+2 hours'));

    expect($token->isAccepted())->toBeTrue()
        ->and($token->isUsable($now->modify('+1 hour')))->toBeFalse()
        ->and($token->acceptedAt())->toEqual($now->modify('+1 hour'));
});

it('is no longer usable once revoked', function () use ($now): void {
    $token = InvitationToken::issue($now);
    $token->revoke($now);

    expect($token->isRevoked())->toBeTrue()
        ->and($token->isUsable($now))->toBeFalse();
});
