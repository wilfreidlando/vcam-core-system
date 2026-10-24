<?php

declare(strict_types=1);

namespace Core\Support\Privacy;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/**
 * AES-256-GCM through the framework's encrypter, one encrypter per key
 * version. The keyring is parsed on first use: an application that does not
 * handle personal data yet boots without the keys.
 */
final class EncrypterPersonalDataCipher implements PersonalDataCipher
{
    private const PREFIX = 'pii:';

    private const CIPHER = 'aes-256-gcm';

    private ?PersonalDataKeyring $keyring = null;

    /** @var array<string, Encrypter> */
    private array $encrypters = [];

    public function __construct(
        private readonly string $configuredKeys,
    ) {}

    public function encrypt(string $plaintext): string
    {
        $keyring = $this->keyring();

        return self::PREFIX.$keyring->currentVersion.':'.$this->encrypter($keyring->currentVersion)->encryptString($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        [$version, $payload] = self::split($ciphertext);

        if ($this->keyring()->key($version) === null) {
            throw UndecryptablePersonalData::unknownVersion($version);
        }

        try {
            return $this->encrypter($version)->decryptString($payload);
        } catch (DecryptException) {
            throw UndecryptablePersonalData::tampered();
        }
    }

    public function needsReEncryption(string $ciphertext): bool
    {
        [$version] = self::split($ciphertext);

        return $version !== $this->keyring()->currentVersion;
    }

    /**
     * @return array{0: string, 1: string} the version (`v1`) and the payload
     */
    private static function split(string $ciphertext): array
    {
        if (! str_starts_with($ciphertext, self::PREFIX)) {
            throw UndecryptablePersonalData::malformed();
        }

        $rest = substr($ciphertext, strlen(self::PREFIX));
        $colon = strpos($rest, ':');
        if ($colon === false || $colon < 2) {
            throw UndecryptablePersonalData::malformed();
        }

        $version = substr($rest, 0, $colon);
        if (preg_match('/^v\d{1,6}$/', $version) !== 1) {
            throw UndecryptablePersonalData::malformed();
        }

        return [$version, substr($rest, $colon + 1)];
    }

    private function keyring(): PersonalDataKeyring
    {
        return $this->keyring ??= PersonalDataKeyring::parse($this->configuredKeys);
    }

    private function encrypter(string $version): Encrypter
    {
        $key = $this->keyring()->key($version) ?? throw UndecryptablePersonalData::unknownVersion($version);

        return $this->encrypters[$version] ??= new Encrypter($key, self::CIPHER);
    }
}
