<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * `CreateProject`'s return shape — a Project is unusable without a
 * credential, so creation always provisions its API key alongside it
 * (ADR-0066: one key per project; the plaintext token exists only here, once — see
 * `IssuedApiKeyView`).
 */
final readonly class ProvisionedProjectView
{
    public function __construct(
        public ProjectView $project,
        public IssuedApiKeyView $apiKey,
    ) {}
}
