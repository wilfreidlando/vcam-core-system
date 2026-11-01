<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Persistence;

use InvalidArgumentException;

/**
 * ADR-0088 §2 — the cursor of a listing.
 *
 * **Opaque**: the base64url of the `(created_at, id)` pair the listing
 * continues from. The pair is the tiebreaker of the order, so the listing
 * never skips nor repeats a row whatever is written while it is read. It
 * carries an instant and an identifier — never a personal value, never a
 * secret.
 */
final class Cursor
{
    public static function encode(string $createdAt, string $id): string
    {
        return rtrim(strtr(base64_encode($createdAt.'|'.$id), '+/', '-_'), '=');
    }

    /**
     * @return array{0: string, 1: string} the instant and the identifier
     *
     * @throws InvalidArgumentException when the cursor is not one of ours
     */
    public static function decode(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = is_string($decoded) ? explode('|', $decoded, 2) : [];

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException('Invalid cursor.');
        }

        // What reaches the database must be what it will accept: an
        // instant and a uuid, never free text.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $parts[1]) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}(:?\d{2})?)?$/', $parts[0]) !== 1) {
            throw new InvalidArgumentException('Invalid cursor.');
        }

        return [$parts[0], $parts[1]];
    }
}
