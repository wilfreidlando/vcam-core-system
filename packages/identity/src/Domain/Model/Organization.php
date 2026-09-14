<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

use InvalidArgumentException;

/**
 * The owning entity in the Core: a client company, an internal entity, a
 * partner. Spec fonctionnelle §4.1.
 */
final class Organization
{
    public function __construct(
        public readonly string $id,
        private string $name,
        private string $status,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('An organization name cannot be blank.');
        }
    }

    public static function create(string $id, string $name): self
    {
        return new self($id, $name, 'active');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
