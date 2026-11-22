<?php

declare(strict_types=1);

namespace App\Billing\ClientApplication;

use Core\Support\Integration\Exception\ProviderException;
use Core\Support\Integration\HttpMethod;
use Core\Support\Integration\Transport\HttpTransport;
use Core\Webhook\Contracts\SignEndpointRequest;
use Core\Webhook\Contracts\SignEndpointRequestCommand;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use JsonException;

/**
 * ADR-0133 §1, §3 — la lecture HTTP d'une application cliente.
 *
 * Le Core **interroge** l'application, et rien d'autre : deux lectures,
 * sur les deux routes que le paquet sert.
 *
 * - la requête est **signée** avec le secret du point d'entrée, selon le
 *   schéma déjà en service pour les événements (ADR-0133 §3) : le secret
 *   ne sort jamais du module `webhook`, et la rotation ne casse pas le
 *   branchement (ADR-0082 §5) ;
 * - la seule sortie HTTP du Core est `Core\Support\Integration` (G6c) :
 *   délai borné, pas de redirection, hôte résolu et épinglé ;
 * - le corps de la réponse est une **donnée non fiable** (ADR-0133 §7) :
 *   il est vérifié avant d'être cru, et il n'est **jamais journalisé** —
 *   il porte des noms, des e-mails et des téléphones.
 */
final readonly class HttpClientApplicationReader implements ReadsClientApplication
{
    /**
     * Le délai que le Core accorde à l'application.
     */
    private const TIMEOUT_SECONDS = 5.0;

    /**
     * Le poids d'une réponse que le Core accepte de lire (1 Mio).
     */
    private const MAX_RESPONSE_BYTES = 1048576;

    /**
     * La route que le paquet sert pour la forme.
     */
    private const TENANT_FORM_PATH = '/core/tenant-schema';

    /**
     * La route que le paquet sert pour les locataires.
     */
    private const TENANTS_PATH = '/core/tenants';

    public function __construct(
        private HttpTransport $transport,
        private SignEndpointRequest $signer,
    ) {}

    public function tenantForm(ClientApplicationEndpoint $endpoint): array
    {
        $payload = $this->read($endpoint, HttpMethod::GET, self::TENANT_FORM_PATH, '');

        return $this->form($payload);
    }

    public function tenants(ClientApplicationEndpoint $endpoint, int $page, int $perPage): array
    {
        try {
            $body = json_encode(['page' => $page, 'per_page' => $perPage], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ClientApplicationReadFailed::unreadable();
        }

        $payload = $this->read($endpoint, HttpMethod::POST, self::TENANTS_PATH, $body);

        $rows = $payload['data'] ?? null;

        if (! is_array($rows)) {
            throw ClientApplicationReadFailed::unreadable();
        }

        $meta = $payload['meta'] ?? null;
        $hasMore = is_array($meta) && ($meta['has_more'] ?? false) === true;

        $lines = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw ClientApplicationReadFailed::unreadable();
            }

            /** @var array<string, mixed> $row */
            $lines[] = $row;
        }

        return ['rows' => $lines, 'has_more' => $hasMore];
    }

    /**
     * La requête, signée, et sa réponse.
     *
     * @return array<string, mixed>
     */
    private function read(ClientApplicationEndpoint $endpoint, HttpMethod $method, string $path, string $body): array
    {
        $headers = ['Accept' => 'application/json'];

        if ($body !== '') {
            $headers['Content-Type'] = 'application/json';
        }

        try {
            $signed = $this->signer->sign(new SignEndpointRequestCommand(
                projectId: $endpoint->projectId,
                endpointId: $endpoint->endpointId,
                body: $body,
            ));
        } catch (WebhookEndpointNotFound) {
            throw ClientApplicationReadFailed::endpointGone();
        }

        try {
            $response = $this->transport->send(
                method: $method,
                url: $endpoint->url($path),
                headers: array_merge($headers, $signed->headers),
                body: $body === '' ? null : $body,
                timeoutSeconds: self::TIMEOUT_SECONDS,
            );
        } catch (ProviderException) {
            throw ClientApplicationReadFailed::notReachable();
        }

        if (strlen($response->rawBody) > self::MAX_RESPONSE_BYTES) {
            throw ClientApplicationReadFailed::tooLarge();
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw $this->refusal($response->status, $response->body);
        }

        $payload = $response->body;

        if (! is_array($payload)) {
            throw ClientApplicationReadFailed::unreadable();
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * La forme annoncée par l'application, vérifiée avant d'être crue.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *     unique_key: string,
     *     unique_key_pattern: string,
     *     fields: list<array{key: string, label: string, type: string, required: bool, max_length: int|null, source: string}>
     * }
     */
    private function form(array $payload): array
    {
        $uniqueKey = $payload['unique_key'] ?? null;
        $pattern = $payload['unique_key_pattern'] ?? null;
        $fields = $payload['fields'] ?? null;

        if (! is_string($uniqueKey) || ! is_string($pattern) || ! is_array($fields)) {
            throw ClientApplicationReadFailed::unreadable();
        }

        $verified = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                throw ClientApplicationReadFailed::unreadable();
            }

            $key = $field['key'] ?? null;
            $label = $field['label'] ?? null;
            $type = $field['type'] ?? null;
            $required = $field['required'] ?? null;
            $source = $field['source'] ?? null;
            $maxLength = $field['max_length'] ?? null;

            if (! is_string($key) || ! is_string($label) || ! is_string($type) || ! is_string($source)) {
                throw ClientApplicationReadFailed::unreadable();
            }

            if (! is_bool($required)) {
                throw ClientApplicationReadFailed::unreadable();
            }

            if ($maxLength !== null && ! is_int($maxLength)) {
                throw ClientApplicationReadFailed::unreadable();
            }

            $verified[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => $required,
                'max_length' => $maxLength,
                'source' => $source,
            ];
        }

        return [
            'unique_key' => $uniqueKey,
            'unique_key_pattern' => $pattern,
            'fields' => $verified,
        ];
    }

    /**
     * Pourquoi la lecture a échoué, d'après le code de la réponse.
     *
     * @param array<string, mixed>|null $body
     */
    private function refusal(int $status, ?array $body): ClientApplicationReadFailed
    {
        if ($status === 401 || $status === 403) {
            return ClientApplicationReadFailed::secretRefused();
        }

        if ($status === 404) {
            return ClientApplicationReadFailed::notYetBranchable();
        }

        if ($status === 422) {
            $error = is_string($body['error'] ?? null) ? $body['error'] : '';
            $message = is_string($body['message'] ?? null) ? $body['message'] : 'la déclaration est invalide.';

            return match ($error) {
                'tenant_schema_not_declared' => ClientApplicationReadFailed::noFormDeclared(),
                'tenant_listing_not_supported' => ClientApplicationReadFailed::cannotListTenants(),
                'invalid_tenant_schema' => ClientApplicationReadFailed::invalidForm($message),
                default => ClientApplicationReadFailed::unreadable(),
            };
        }

        return ClientApplicationReadFailed::notReachable();
    }
}
