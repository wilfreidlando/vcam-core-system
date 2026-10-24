<?php

declare(strict_types=1);

namespace Core\Support\Privacy;

final class HmacBlindIndex implements BlindIndex
{
    private ?string $key = null;

    public function __construct(
        private readonly string $configuredKey,
    ) {}

    public function of(string $value, PersonalDataKind $kind): string
    {
        return hash_hmac('sha256', $kind->value.':'.$kind->normalize($value), $this->key());
    }

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        if (preg_match('/^base64:([A-Za-z0-9+\/=]+)$/', trim($this->configuredKey), $m) !== 1) {
            throw MissingPersonalDataKey::blindIndex('expected "base64:<key>".');
        }

        $raw = base64_decode($m[1], true);
        if ($raw === false || strlen($raw) < 32) {
            throw MissingPersonalDataKey::blindIndex('the key must decode to at least 32 bytes.');
        }

        return $this->key = $raw;
    }
}
