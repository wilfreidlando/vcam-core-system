<?php

declare(strict_types=1);

namespace Core\Export\Infrastructure\Storage;

use Illuminate\Contracts\Encryption\StringEncrypter;
use RuntimeException;

/**
 * Encrypts a file of any size with the application key without ever
 * holding it in memory (ADR-0088 §3 — « chiffré au repos »).
 *
 * The file is cut in chunks of one mebibyte and each chunk goes through the
 * application's authenticated encrypter (`Crypt`). The result is
 * `EXP1` followed by frames `<length: uint32 big-endian><payload>`; a frame
 * altered, dropped from the middle or cut short fails the decryption — a
 * truncated file after its last whole frame is the one case the format
 * cannot see, which the final frame count in the trailer closes.
 *
 * Trailer: the last frame holds the number of data frames, so a file cut
 * between two frames is refused too.
 */
final class ChunkedEncryption
{
    private const MAGIC = 'EXP1';

    private const CHUNK = 1_048_576;

    private const MAX_FRAME = 4_194_304;

    public function __construct(private readonly StringEncrypter $encrypter) {}

    /**
     * @param resource $plain
     * @param resource $encrypted
     */
    public function encrypt(mixed $plain, mixed $encrypted): void
    {
        $this->write($encrypted, self::MAGIC);
        $frames = 0;

        while (! feof($plain)) {
            $chunk = fread($plain, self::CHUNK);

            if ($chunk === false) {
                throw new RuntimeException('The archive could not be read.');
            }

            if ($chunk === '') {
                continue;
            }

            $this->frame($encrypted, $chunk);
            $frames++;
        }

        // The trailer: how many data frames there are.
        $this->frame($encrypted, 'END:'.$frames);
    }

    /**
     * @param resource $encrypted
     * @param resource $output
     */
    public function decrypt(mixed $encrypted, mixed $output): void
    {
        if (fread($encrypted, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new RuntimeException('The export file is not an encrypted export.');
        }

        $frames = 0;
        $pending = null;

        while (true) {
            $header = fread($encrypted, 4);

            if ($header === false || $header === '') {
                break;
            }

            $unpacked = strlen($header) === 4 ? unpack('N', $header) : false;
            $length = is_array($unpacked) ? $unpacked[1] : null;

            if (! is_int($length) || $length < 1 || $length > self::MAX_FRAME) {
                throw new RuntimeException('The export file is corrupted.');
            }

            $payload = $this->readExactly($encrypted, $length);
            $chunk = $this->encrypter->decryptString($payload);

            // One frame is held back: the last one is the trailer, not data.
            if ($pending !== null) {
                $this->write($output, $pending);
                $frames++;
            }

            $pending = $chunk;
        }

        if ($pending === null || $pending !== 'END:'.$frames) {
            throw new RuntimeException('The export file is truncated.');
        }
    }

    /**
     * @param resource $encrypted
     */
    private function frame(mixed $encrypted, string $chunk): void
    {
        $payload = $this->encrypter->encryptString($chunk);
        $this->write($encrypted, pack('N', strlen($payload)).$payload);
    }

    /**
     * @param resource $stream
     */
    private function write(mixed $stream, string $bytes): void
    {
        if (fwrite($stream, $bytes) === false) {
            throw new RuntimeException('The export could not be written.');
        }
    }

    /**
     * @param resource $stream
     */
    private function readExactly(mixed $stream, int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $part = fread($stream, max(1, $length - strlen($data)));

            if ($part === false || $part === '') {
                throw new RuntimeException('The export file is truncated.');
            }

            $data .= $part;
        }

        return $data;
    }
}
