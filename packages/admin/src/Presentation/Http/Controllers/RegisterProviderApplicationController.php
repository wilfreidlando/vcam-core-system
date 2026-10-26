<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Admin\Presentation\Http\Controllers\Concerns\AuthorizesInScope;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
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
    use AuthorizesInScope;

    public function __construct(
        private readonly RegisterProviderApplication $registerProviderApplication,
        private readonly GetProject $getProject,
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
        $scope = $this->permittedScope($operator, 'provider-applications:write');
        if ($scope === null) {
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

        // ADR-0069 §2 / S-04 — 404 for a project out of scope, 403 when the
        // account's role in its organization lacks the permission.
        $refusal = $this->projectRefusal($this->getProject, $operator, $data['project_id'], 'provider-applications:write');
        if ($refusal === 'not-found') {
            return ProblemDetails::make('project-not-found', 'Project not found', 404, sprintf('Project %s does not exist.', $data['project_id']));
        }
        if ($refusal === 'forbidden') {
            return ProblemDetails::forbidden('The provider-applications:write permission is required on this project.');
        }
        $organizationId = $this->getProject->get(new GetProjectCommand($data['project_id']), $operator->scope)->organizationId;

        try {
            $rotated = $this->registerProviderApplication->register(new RegisterProviderApplicationCommand(
                $data['project_id'],
                $data['provider'],
                $data['public_key'],
                $data['private_key'] ?? null,
                $data['label'] ?? null,
            ), $scope);
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
            projectId: $data['project_id'],
            organizationId: $organizationId,
        ));

        return new JsonResponse([
            'project_id' => $data['project_id'],
            'provider_code' => $data['provider'],
            'public_key' => $data['public_key'],
        ], 201);
    }
}
