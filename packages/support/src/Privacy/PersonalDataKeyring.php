<?php

declare(strict_types=1);

namespace Core\Support\Privacy;

/**
 * `PII_ENCRYPTION_KEYS="v2:base64:…,v1:base64:…"` — the first entry encrypts,
 * every entry decrypts. Each key is 32 random bytes (AES-256).
 *
 * Versions are kept as `v<number>` strings internally, so a version like
 * `1` never becomes the integer key `1` of a PHP array.
 */
final readonly class PersonalDataKeyring
{
    /**
     * @param array<string, string> $keys `v<version>` => raw 32-byte key, current first
     */
    private function __construct(
        public string $currentVersion,
        private array $keys,
    ) {}

    public static function parse(string $configured): self
    {
        $keys = [];
        foreach (array_filter(array_map('trim', explode(',', $configured)), static fn (string $e): bool => $e !== '') as $entry) {
            if (preg_match('/^v(\d{1,6}):base64:([A-Za-z0-9+\/=]+)$/', $entry, $m) !== 1) {
                throw MissingPersonalDataKey::encryption('expected "v<number>:base64:<key>" entries separated by commas.');
            }

            $raw = base64_decode($m[2], true);
            if ($raw === false || strlen($raw) !== 32) {
                throw MissingPersonalDataKey::encryption(sprintf('key v%s must decode to 32 bytes.', $m[1]));
            }

            $version = 'v'.$m[1];
            if (isset($keys[$version])) {
                throw MissingPersonalDataKey::encryption(sprintf('version v%s appears twice.', $m[1]));
            }

            $keys[$version] = $raw;
        }

        if ($keys === []) {
            throw MissingPersonalDataKey::encryption('no key configured.');
        }

        $versions = array_keys($keys);

        return new self($versions[0], $keys);
    }

    public function key(string $version): ?string
    {
        return $this->keys[$version] ?? null;
    }

    public function currentKey(): string
    {
        return $this->keys[$this->currentVersion];
    }
}
