<?php

declare(strict_types=1);

namespace App\Billing\ClientApplication;

use InvalidArgumentException;

/**
 * ADR-0133 §2 — l'adresse de l'application, et le point d'entrée qui
 * l'authentifie.
 *
 * L'adresse est **déduite** du point d'entrée déjà enregistré pour le
 * projet : même schéma, même hôte, même port. L'opérateur ne saisit pas une
 * seconde adresse — et la console peut la corriger, au cas où l'API de
 * l'application ne serait pas sur le même hôte que la réception des
 * événements.
 *
 * Le point d'entrée porte le **secret** : c'est lui qui signe la requête
 * que le Core adresse à l'application (ADR-0133 §3).
 */
final readonly class ClientApplicationEndpoint
{
    public function __construct(
        public string $projectId,
        public string $endpointId,
        /** L'adresse de base de l'application, sans slash final. */
        public string $baseUrl,
    ) {}

    /**
     * L'adresse déduite du point d'entrée : ce qu'il y a de commun entre
     * l'adresse de réception des événements et l'adresse de l'API.
     */
    public static function derivedFrom(string $projectId, string $endpointId, string $endpointUrl): self
    {
        return new self(
            projectId: $projectId,
            endpointId: $endpointId,
            baseUrl: self::baseUrlOf($endpointUrl),
        );
    }

    /**
     * L'adresse de base déduite de l'adresse d'un point d'entrée : ce qu'il
     * y a de commun entre la réception des événements et l'API.
     */
    public static function baseUrlOf(string $endpointUrl): string
    {
        $parts = parse_url($endpointUrl);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = is_array($parts) && isset($parts['host']) ? $parts['host'] : '';
        $port = is_array($parts) && isset($parts['port']) ? $parts['port'] : null;

        if ($host === '') {
            throw new InvalidArgumentException('L\'adresse du point d\'entrée ne porte pas d\'hôte.');
        }

        return self::secured($scheme.'://'.$host.($port === null ? '' : ':'.$port));
    }

    /**
     * L'adresse **corrigée** par l'opérateur.
     */
    public static function at(string $projectId, string $endpointId, string $baseUrl): self
    {
        $trimmed = rtrim(trim($baseUrl), '/');
        $parts = parse_url($trimmed);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = is_array($parts) && isset($parts['host']) ? $parts['host'] : '';

        if ($host === '') {
            throw new InvalidArgumentException('L\'adresse de l\'application ne porte pas d\'hôte.');
        }

        return new self(
            projectId: $projectId,
            endpointId: $endpointId,
            baseUrl: self::secured($trimmed),
        );
    }

    /**
     * L'URL d'une lecture, sur le chemin que le paquet sert.
     */
    public function url(string $path): string
    {
        return $this->baseUrl.$path;
    }

    /**
     * HTTPS, sauf la boucle locale — qui ne quitte pas la machine.
     *
     * ADR-0133 §6 — le secret du point d'entrée ne doit pas voyager en
     * clair sur le réseau.
     */
    private static function secured(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = is_array($parts) && isset($parts['host']) ? $parts['host'] : '';

        if ($scheme === 'https') {
            return $baseUrl;
        }

        if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return $baseUrl;
        }

        throw new InvalidArgumentException('L\'adresse de l\'application doit être en HTTPS.');
    }
}
