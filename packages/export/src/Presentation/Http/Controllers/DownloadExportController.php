<?php

declare(strict_types=1);

namespace Core\Export\Presentation\Http\Controllers;

use Core\Export\Contracts\DownloadExport;
use Core\Export\Contracts\ExportNotAvailable;
use Core\Export\Contracts\ExportNotFound;
use Core\Export\Contracts\SignedDownloadLinks;
use Core\Identity\Contracts\OperatorContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /exports/{exportId}/download` — the signed link of ADR-0088 §3.
 *
 * Authenticated by the signature (the `signed` middleware): an altered or
 * expired link is a `403` before this controller runs. A link handed out by
 * the console also names an operator, who must be the one signed in to the
 * console, within the scope of the export's project (`forOperator`). A link
 * works once. The archive is decrypted while it streams, one chunk at a time.
 */
final class DownloadExportController
{
    public function __construct(
        private readonly DownloadExport $download,
    ) {}

    /**
     * @throws ExportNotFound
     * @throws ExportNotAvailable
     */
    public function __invoke(Request $request, string $exportId): StreamedResponse
    {
        return $this->respond($request, $exportId);
    }

    /**
     * The console's link: `{operatorId}` is in the signed path, and the
     * `operator.session` middleware has resolved the operator of this browser.
     *
     * @throws ExportNotFound
     * @throws ExportNotAvailable
     */
    public function forOperator(Request $request, OperatorContext $operator, string $exportId, string $operatorId): StreamedResponse
    {
        if ($operator->operatorId !== $operatorId) {
            abort(403, 'Ce lien de téléchargement a été émis pour un autre compte.');
        }

        return $this->respond($request, $exportId, $operator);
    }

    private function respond(Request $request, string $exportId, ?OperatorContext $operator = null): StreamedResponse
    {
        // Refuses (404 / 409) before any byte is sent.
        $file = $this->download->prepare($exportId);

        if ($operator !== null && ! $operator->scope->allowsProject($file->projectId)) {
            abort(403, 'Ce compte n\'a plus accès au projet de cet export.');
        }

        // Single use: the first request that gets here spends the link. The
        // key lives as long as the link is valid, then the signature expires
        // by itself.
        $signature = $request->query('signature');

        if (! is_string($signature) || ! Cache::add('exports:download-spent:'.hash('sha256', $signature), true, now()->addMinutes(SignedDownloadLinks::VALIDITY_MINUTES + 1))) {
            abort(403, 'Ce lien de téléchargement a déjà été utilisé : demandez-en un nouveau.');
        }

        return new StreamedResponse(function () use ($exportId): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            try {
                $this->download->stream($exportId, $output);
            } finally {
                fclose($output);
            }
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$file->filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            // The signed URL is a secret (audit 2026-10-29, G-11): this
            // route is outside the `/admin` group, so the console's
            // `Referrer-Policy` does not apply here — it must be carried
            // by the response itself.
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
