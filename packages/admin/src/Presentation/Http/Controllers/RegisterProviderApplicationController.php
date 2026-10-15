<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\OperatorContext;
use Core\Payment\Contracts\RegisterProviderApplication;
use Core\Payment\Contracts\RegisterProviderApplicationCommand;
use Core\Support\Clock;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class RegisterProviderApplicationController
{
    public function __construct(
        private readonly RegisterProviderApplication $registerProviderApplication,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * Register (or rotate) a Project's application/merchant-account with a
     * payment provider.
     *
     * Requires `provider-applications:write`. `payment` cannot depend on
     * `audit` (module graph), so this controller records the audit entry
     * itself after calling the port — the private key is never included.
     */
    public function __invoke(Request $request, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('provider-applications:write')) {
            return ProblemDetails::forbidden('The provider-applications:write permission is required.');
        }

        /** @var array{project_id: string, provider: string, label?: string|null, public_key: string, private_key?: string|null} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'provider' => ['required', 'string'],
            'label' => ['nullable', 'string', 'max:120'],
            'public_key' => ['required', 'string', 'max:1024'],
            // Optional on update (keeps the stored key), required on creation.
            'private_key' => ['nullable', 'string', 'max:1024'],
        ]);

        try {
            $rotated = $this->registerProviderApplication->register(new RegisterProviderApplicationCommand(
                $data['project_id'],
                $data['provider'],
                $data['public_key'],
                $data['private_key'] ?? null,
                $data['label'] ?? null,
            ));
        } catch (RuntimeException $e) {
            // ADR-0060: every failure carries an operator-readable message
            // (unknown project, public key already used elsewhere, missing
            // private key) — never a raw SQL error.
            return ProblemDetails::make('provider-application-rejected', 'Provider application rejected', 422, $e->getMessage());
        }

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: $rotated ? 'provider_application.rotated' : 'provider_application.registered',
            resource: 'provider_application:'.$data['project_id'].':'.$data['provider'],
            before: null,
            after: [
                'project_id' => $data['project_id'],
                'provider_code' => $data['provider'],
                'public_key' => $data['public_key'],
            ],
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
        ));

        return new JsonResponse([
            'project_id' => $data['project_id'],
            'provider_code' => $data['provider'],
            'public_key' => $data['public_key'],
        ], 201);
    }
}
