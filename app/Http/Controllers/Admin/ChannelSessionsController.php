<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Notification\Contracts\ChannelSessionNotFound;
use Core\Notification\Contracts\CreateChannelSession;
use Core\Notification\Contracts\CreateChannelSessionCommand;
use Core\Notification\Contracts\GetChannelSessionCommand;
use Core\Notification\Contracts\GetChannelSessionQrCode;
use Core\Notification\Contracts\GetChannelSessionScreenshot;
use Core\Notification\Contracts\ListChannelSessions;
use Core\Notification\Contracts\ListChannelSessionsCommand;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\RequestChannelSessionPairingCode;
use Core\Notification\Contracts\RequestChannelSessionPairingCodeCommand;
use Core\Support\Clock;
use Core\Support\Integration\Exception\ProviderException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where WhatsApp sessions come into being (ADR-0057). A client never
 * creates one: the operator does it here — for one Project, or for the
 * platform (`$projectId === null`, a global session every Project may
 * send with). Creating a session both starts it at the provider (so it
 * really exists and can be linked) and registers it in the Core's own
 * table, which is what every send is later checked against.
 *
 * Next to each session: QR code and screenshot (to link a phone and check
 * its state) and a pairing code (link by phone number instead of QR).
 */
final class ChannelSessionsController
{
    private const CHANNEL = 'whatsapp';

    private const PERMISSION = 'channel-sessions:write';

    public function __construct(
        private readonly CreateChannelSession $createSession,
        private readonly ListChannelSessions $listSessions,
        private readonly GetChannelSessionQrCode $getQrCode,
        private readonly GetChannelSessionScreenshot $getScreenshot,
        private readonly RequestChannelSessionPairingCode $requestPairingCode,
        private readonly GetProject $getProject,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        $projectId = $this->projectId($request);
        $this->authorize($operator, $projectId);

        return response()->view('admin.channel-sessions.index', [
            'title' => 'Sessions WhatsApp',
            'projectId' => $projectId,
            'projectName' => $this->projectName($projectId),
            'sessions' => $this->listSessions->list(new ListChannelSessionsCommand($projectId, NotificationChannel::fromString(self::CHANNEL))),
        ]);
    }

    public function store(Request $request, OperatorContext $operator): RedirectResponse
    {
        $projectId = $this->projectId($request);
        $this->authorize($operator, $projectId);

        /** @var array{session_ref: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'session_ref' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        try {
            $this->createSession->create(new CreateChannelSessionCommand(
                $projectId,
                NotificationChannel::fromString(self::CHANNEL),
                $data['session_ref'],
            ));
        } catch (ProviderException $e) {
            return back()->withInput()->withErrors(['session_ref' => 'Le fournisseur WhatsApp a refusé la création : '.$e->getMessage()]);
        }

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'channel_session.created',
            resource: 'channel_session:'.($projectId ?? 'platform').':'.$data['session_ref'],
            before: null,
            after: ['project_id' => $projectId, 'channel' => self::CHANNEL, 'session_ref' => $data['session_ref']],
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
        ));

        return redirect()->to($this->indexUrl($projectId))
            ->with('status', "Session « {$data['session_ref']} » créée et démarrée. Scannez le QR code (ou demandez un code d'appairage) pour lier le téléphone.");
    }

    public function qr(Request $request, OperatorContext $operator): Response
    {
        $projectId = $this->projectId($request);
        $sessionRef = $this->sessionRef($request);
        $this->authorize($operator, $projectId);

        return $this->showImage($projectId, $sessionRef, 'QR code', function () use ($projectId, $sessionRef): array {
            $qr = $this->getQrCode->get($this->command($projectId, $sessionRef));

            return [$qr->mimeType, $qr->base64Data];
        });
    }

    public function screenshot(Request $request, OperatorContext $operator): Response
    {
        $projectId = $this->projectId($request);
        $sessionRef = $this->sessionRef($request);
        $this->authorize($operator, $projectId);

        return $this->showImage($projectId, $sessionRef, "Capture d'écran", function () use ($projectId, $sessionRef): array {
            $shot = $this->getScreenshot->get($this->command($projectId, $sessionRef));

            return [$shot->mimeType, $shot->base64Data];
        });
    }

    public function pairingCode(Request $request, OperatorContext $operator): RedirectResponse
    {
        $projectId = $this->projectId($request);
        $sessionRef = $this->sessionRef($request);
        $this->authorize($operator, $projectId);

        /** @var array{phone_number: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate(['phone_number' => ['required', 'string', 'max:32']]);

        try {
            $view = $this->requestPairingCode->request(new RequestChannelSessionPairingCodeCommand(
                $projectId,
                NotificationChannel::fromString(self::CHANNEL),
                $sessionRef,
                $data['phone_number'],
            ));
        } catch (ChannelSessionNotFound|ProviderException $e) {
            return redirect()->to($this->indexUrl($projectId))->withErrors(['pairing' => $e->getMessage()]);
        }

        return redirect()->to($this->indexUrl($projectId))
            ->with('status', "Code d'appairage pour « {$sessionRef} » : {$view->code} — saisissez-le dans WhatsApp › Appareils connectés › Associer avec le numéro de téléphone.");
    }

    /**
     * @param callable(): array{0: string, 1: string} $fetch
     */
    private function showImage(?string $projectId, string $sessionRef, string $label, callable $fetch): Response
    {
        $image = null;
        $error = null;
        try {
            [$mime, $data] = $fetch();
            $image = 'data:'.$mime.';base64,'.$data;
        } catch (ChannelSessionNotFound|ProviderException $e) {
            $error = $e->getMessage();
        }

        return response()->view('admin.channel-sessions.show', [
            'title' => $label.' · '.$sessionRef,
            'label' => $label,
            'sessionRef' => $sessionRef,
            'image' => $image,
            'error' => $error,
            'backUrl' => $this->indexUrl($projectId),
        ]);
    }

    private function command(?string $projectId, string $sessionRef): GetChannelSessionCommand
    {
        return new GetChannelSessionCommand($projectId, NotificationChannel::fromString(self::CHANNEL), $sessionRef);
    }

    /**
     * Route parameters are read BY NAME: Laravel fills untyped controller
     * arguments by position, which silently swaps `projectId`/`sessionRef`
     * (and puts the lone `sessionRef` of a global route into `projectId`).
     * `null` means the platform's global sessions (no `{projectId}` in the URL).
     */
    private function projectId(Request $request): ?string
    {
        $value = $request->route('projectId');

        return is_string($value) ? $value : null;
    }

    private function sessionRef(Request $request): string
    {
        $value = $request->route('sessionRef');

        return is_string($value) ? $value : '';
    }

    private function authorize(OperatorContext $operator, ?string $projectId): void
    {
        if (! $operator->hasPermission(self::PERMISSION)) {
            abort(403, 'La permission '.self::PERMISSION.' est requise.');
        }

        if ($projectId !== null) {
            Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();
        }
    }

    private function projectName(?string $projectId): ?string
    {
        if ($projectId === null) {
            return null;
        }

        try {
            return $this->getProject->get(new GetProjectCommand($projectId))->name;
        } catch (RuntimeException) {
            abort(404, 'Projet introuvable.');
        }
    }

    private function indexUrl(?string $projectId): string
    {
        return $projectId === null
            ? route('admin.channel-sessions.index')
            : route('admin.projects.channel-sessions.index', ['projectId' => $projectId]);
    }
}
