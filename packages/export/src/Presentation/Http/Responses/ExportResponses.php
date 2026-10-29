<?php

declare(strict_types=1);

namespace Core\Export\Presentation\Http\Responses;

/**
 * The documented shape of an export — it follows `ExportJson::view()` key
 * for key.
 */
final class ExportResponses
{
    public const EXPORT = 'array{'
        .'id: string, '
        .'status: string, '
        .'created_at: string, '
        .'finished_at: string|null, '
        .'expires_at: string, '
        .'error: string|null, '
        .'download_url: string|null, '
        .'download_url_expires_at: string|null'
        .'}';
}
