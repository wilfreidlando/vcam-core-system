<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Billing\ClientApplication\ClientApplicationConnection;
use App\Billing\ClientApplication\ClientApplicationConnections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-0133 §2 — le branchement retenu, dans la table de la coquille.
 *
 * Une ligne par projet : le point d'entrée qui porte le secret, et
 * l'adresse corrigée quand il y en a une.
 */
final class DatabaseClientApplicationConnections implements ClientApplicationConnections
{
    public function find(string $projectId): ?ClientApplicationConnection
    {
        $row = DB::table('client_application_connections')
            ->where('project_id', $projectId)
            ->first();

        if ($row === null) {
            return null;
        }

        $endpointId = $row->endpoint_id;
        $baseUrl = $row->base_url;

        return new ClientApplicationConnection(
            projectId: $projectId,
            endpointId: is_string($endpointId) ? $endpointId : null,
            baseUrl: is_string($baseUrl) ? $baseUrl : null,
        );
    }

    public function save(ClientApplicationConnection $connection): void
    {
        $table = DB::table('client_application_connections');

        $existing = $table->where('project_id', $connection->projectId)->first();

        if ($existing === null) {
            $table->insert([
                'id' => (string) Str::uuid(),
                'project_id' => $connection->projectId,
                'endpoint_id' => $connection->endpointId,
                'base_url' => $connection->baseUrl,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $table->where('project_id', $connection->projectId)->update([
            'endpoint_id' => $connection->endpointId,
            'base_url' => $connection->baseUrl,
            'updated_at' => now(),
        ]);
    }
}
