<?php

declare(strict_types=1);

namespace Core\Export\Presentation\Http;

use Core\Export\Contracts\ExportView;

/**
 * The public JSON shape of an export.
 */
final class ExportJson
{
    /**
     * @param array{url: string, expires_at: string}|null $link the signed link, only when the file can be downloaded
     * @return array<string, mixed>
     */
    public static function view(ExportView $view, ?array $link = null): array
    {
        return [
            'id' => $view->id,
            'status' => $view->status,
            'created_at' => $view->createdAt,
            'finished_at' => $view->finishedAt,
            'expires_at' => $view->expiresAt,
            'error' => $view->error,
            'download_url' => $link['url'] ?? null,
            'download_url_expires_at' => $link['expires_at'] ?? null,
        ];
    }
}
