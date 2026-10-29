<?php

declare(strict_types=1);

namespace Core\Export\Presentation\Support;

use Core\Export\Contracts\SignedDownloadLinks;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\URL;

/**
 * ADR-0088 §3 — a temporary signed route, fifteen minutes, usable once.
 *
 * When the link is asked for by a console operator (the console session puts
 * `operator_id` in the request context), that operator is carried in the
 * signed path and checked again at download: the link is tied to the person
 * who clicked, not just to the export. A link asked for with a project API key
 * has no operator and is authenticated by its signature alone.
 */
final class SignedExportLinks implements SignedDownloadLinks
{
    public function forExport(string $exportId): string
    {
        $operatorId = Context::get('operator_id');
        $expires = now()->addMinutes(self::VALIDITY_MINUTES);

        if (is_string($operatorId) && $operatorId !== '') {
            return URL::temporarySignedRoute('exports.download.operator', $expires, ['exportId' => $exportId, 'operatorId' => $operatorId]);
        }

        return URL::temporarySignedRoute('exports.download', $expires, ['exportId' => $exportId]);
    }
}
