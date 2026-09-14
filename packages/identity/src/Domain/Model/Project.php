<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

use InvalidArgumentException;

/**
 * A concrete application, platform or integration consuming the Core. It is
 * the Project that authenticates to the Core (spec fonctionnelle §4.2) — not
 * the organization, and never the organization's end-users.
 */
final class Project
{
    public function __construct(
        public readonly string $id,
        public readonly string $organizationId,
        private string $name,
        private string $status,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A project name cannot be blank.');
        }
    }

    public static function create(string $id, string $organizationId, string $name): self
    {
        return new self($id, $organizationId, $name, 'active');
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
