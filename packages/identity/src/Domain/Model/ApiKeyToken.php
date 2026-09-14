<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

use Core\Identity\Domain\Exception\InvalidApiKey;

/**
 * The bearer token format: `{prefix}.{secret}`. The prefix is visible
 * everywhere (logs, the Core Admin UI, the credential shown once at
 * issuance) and exists purely so a presented token can be looked up by an
 * indexed column before the expensive part — verifying the secret's hash —
 * ever runs. The prefix carries no security value by itself.
 */
final readonly class ApiKeyToken
{
    private const SEPARATOR = '.';

    private function __construct(
        public string $prefix,
        public string $secret,
    ) {}

    public static function compose(string $prefix, string $secret): self
    {
        return new self($prefix, $secret);
    }

    /**
     * Splits on the FIRST separator, not the last: the prefix's shape
     * (`pk_<env>_<8 chars>`, alphanumeric only) guarantees it never contains
     * one, while the secret's encoding is free to change — a future format
     * containing its own dots must still round-trip as one whole secret.
     *
     * @throws InvalidApiKey if the token does not have the `prefix.secret` shape.
     */
    public static function parse(string $bearerToken): self
    {
        $position = strpos($bearerToken, self::SEPARATOR);

        if ($position === false || $position === 0 || $position === strlen($bearerToken) - 1) {
            throw InvalidApiKey::malformed();
        }

        return new self(
            substr($bearerToken, 0, $position),
            substr($bearerToken, $position + 1),
        );
    }

    public function value(): string
    {
        return $this->prefix.self::SEPARATOR.$this->secret;
    }
}
