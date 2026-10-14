<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Exception;

use RuntimeException;

/**
 * The public key routes inbound callbacks to their project, so it can
 * belong to only one project. Replaces the raw
 * PostgreSQL "duplicate key" message operators used to see.
 */
final class ProviderApplicationPublicKeyAlreadyUsed extends RuntimeException
{
    public function __construct(
        public readonly string $ownerProjectId,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : sprintf(
            'Cette clé publique est déjà rattachée à un autre projet (projet %s). '
            .'Une application provider ne peut servir qu\'un seul projet : '
            .'c\'est elle qui permet au Core d\'attribuer chaque callback au bon projet. '
            .'Créez une application distincte chez le provider.',
            $ownerProjectId,
        ));
    }
}
