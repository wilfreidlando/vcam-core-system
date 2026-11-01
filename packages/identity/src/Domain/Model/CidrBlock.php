<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

use Core\Identity\Domain\Exception\InvalidApiKeySettings;

/**
 * ADR-0082 §4 — one network block a key may be used from (IPv4 or IPv6).
 * A bare address is a block of one (`/32` or `/128`).
 */
final readonly class CidrBlock
{
    private function __construct(
        private string $network,
        private int $prefixLength,
    ) {}

    public static function parse(string $value): self
    {
        $value = trim($value);
        $parts = explode('/', $value, 2);
        $address = $parts[0];
        $length = $parts[1] ?? null;

        $packed = @inet_pton($address);
        if ($packed === false) {
            throw InvalidApiKeySettings::cidr($value);
        }

        $maxLength = strlen($packed) * 8;
        if ($length === null) {
            $prefixLength = $maxLength;
        } elseif (ctype_digit($length) && (int) $length <= $maxLength) {
            $prefixLength = (int) $length;
        } else {
            throw InvalidApiKeySettings::cidr($value);
        }

        // `::ffff:203.0.113.0/120` is the IPv4 block 203.0.113.0/24 written
        // in IPv6: store it as such, so it matches native IPv4 clients.
        if (self::isIpv4Mapped($packed)) {
            if ($prefixLength < 96) {
                throw InvalidApiKeySettings::cidr($value);
            }
            $packed = substr($packed, 12);
            $prefixLength -= 96;
        }

        return new self(self::mask($packed, $prefixLength), $prefixLength);
    }

    public function contains(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        // A dual-stack listener reports IPv4 clients as `::ffff:a.b.c.d`.
        if (self::isIpv4Mapped($packed)) {
            $packed = substr($packed, 12);
        }

        if (strlen($packed) !== strlen($this->network)) {
            return false;
        }

        return hash_equals($this->network, self::mask($packed, $this->prefixLength));
    }

    /** Canonical form, as stored: network address, then the prefix length. */
    public function toString(): string
    {
        return inet_ntop($this->network).'/'.$this->prefixLength;
    }

    private static function isIpv4Mapped(string $packed): bool
    {
        return strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff");
    }

    private static function mask(string $packed, int $prefixLength): string
    {
        $masked = '';
        foreach (str_split($packed) as $i => $byte) {
            $bits = max(0, min(8, $prefixLength - $i * 8));
            $masked .= chr(ord($byte) & ((0xFF << (8 - $bits)) & 0xFF));
        }

        return $masked;
    }
}
