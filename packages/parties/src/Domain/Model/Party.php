<?php

declare(strict_types=1);

namespace Core\Parties\Domain\Model;

use InvalidArgumentException;

/**
 * A light reference to an economic actor a product asks the Core to act on
 * behalf of — never the actor's identity. Created lazily (spec fonctionnelle
 * §4.3): only at the first moment a product needs a Core artefact (a wallet,
 * a payout beneficiary) for this actor.
 */
final class Party
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly string $externalRef,
        private string $type,
        private string $kycStatus,
        private array $metadata,
        // ADR-0065 — `live` or `sandbox`: the same external_ref names one
        // party per environment, never shared across the two.
        public readonly string $environment = 'live',
    ) {
        if (trim($externalRef) === '') {
            throw new InvalidArgumentException('A party external_ref cannot be blank.');
        }

        if (trim($type) === '') {
            throw new InvalidArgumentException('A party type cannot be blank.');
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function create(string $id, string $projectId, string $externalRef, string $type, array $metadata = [], string $environment = 'live'): self
    {
        return new self($id, $projectId, $externalRef, $type, 'none', $metadata, $environment);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function kycStatus(): string
    {
        return $this->kycStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
