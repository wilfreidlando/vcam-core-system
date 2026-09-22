<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * `CreateProject`'s return shape — a Project is unusable without a
 * credential, so creation always provisions one API key per environment
 * alongside it (both plaintext tokens exist only here, once — see
 * `IssuedApiKeyView`).
 */
final readonly class ProvisionedProjectView
{
    public function __construct(
        public ProjectView $project,
        public IssuedApiKeyView $sandboxApiKey,
        public IssuedApiKeyView $liveApiKey,
    ) {}
}
