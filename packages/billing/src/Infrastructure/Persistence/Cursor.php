<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence;

use InvalidArgumentException;

/**
 * ADR-0088 §2 — the cursor of a listing.
 *
 * The cursor is **opaque**: the base64url of the `(created_at, id)` pair
 * the listing continues from. The pair is the tiebreaker of the order, so
 * the listing never skips nor repeats a row, whatever is written while
 * it is read.
 *
 * It carries an instant and an identifier — never a personal value, never
 * a secret.
 */
final class Cursor
{
    /**
     * The cursor of the page that follows the given row.
     */
    public static function encode(string $createdAt, string $id): string
    {
        return rtrim(strtr(base64_encode($createdAt.'|'.$id), '+/', '-_'), '=');
    }

    /**
     * The pair the cursor stands for.
     *
     * @return array{0: string, 1: string} the instant and the identifier
     *
     * @throws InvalidArgumentException when the cursor is not one of ours
     */
    public static function decode(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = is_string($decoded) ? explode('|', $decoded, 2) : [];

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException('Invalid billing cursor.');
        }

        return [$parts[0], $parts[1]];
    }
}
