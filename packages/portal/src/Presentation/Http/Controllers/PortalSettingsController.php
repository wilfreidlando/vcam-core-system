<?php

declare(strict_types=1);

namespace Core\Portal\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Portal\Application\Dto\PortalSettingsData;
use Core\Portal\Application\UseCase\PortalSettingsService;
use Core\Portal\Presentation\Http\Requests\SavePortalSettingsRequest;
use Core\Portal\Presentation\Http\Responses\PortalResponses;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET|PUT /v1/portal/settings` — ADR-0075 §4.
 */
final class PortalSettingsController
{
    public function __construct(private readonly PortalSettingsService $settings) {}

    /**
     * Read the look and the permissions of your portal.
     *
     * Requires the `portal:write` scope.
     */
    #[ScrambleResponse(status: 200, type: PortalResponses::SETTINGS, description: 'The settings of the portal (the defaults when you never set any).')]
    public function show(ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('portal:write')) {
            return ProblemDetails::forbidden('The portal:write scope is required.');
        }

        return new JsonResponse($this->body($this->settings->get($project->projectId)));
    }

    /**
     * Set the look and the permissions of your portal.
     *
     * `allowed_return_urls` is the only list of places the portal will ever
     * send a customer back to; anything else is refused. Requires `portal:write`.
     */
    #[ScrambleResponse(status: 200, type: PortalResponses::SETTINGS, description: 'The settings now in force.')]
    public function update(SavePortalSettingsRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('portal:write')) {
            return ProblemDetails::forbidden('The portal:write scope is required.');
        }

        /** @var array{display_name: string, logo_url?: string|null, primary_color?: string|null, support_url?: string|null, locale?: string|null, allowed_return_urls?: list<string>|null, allow_self_cancel?: bool|null, allow_plan_change?: bool|null, payment_channels?: list<string>|null, direct_login_enabled?: bool|null, direct_login_session?: string|null} $data */
        $data = $request->validated();

        $saved = $this->settings->save($project->projectId, new PortalSettingsData(
            displayName: $data['display_name'],
            logoUrl: is_string($data['logo_url'] ?? null) ? $data['logo_url'] : null,
            primaryColor: is_string($data['primary_color'] ?? null) ? $data['primary_color'] : '#2563eb',
            supportUrl: is_string($data['support_url'] ?? null) ? $data['support_url'] : null,
            locale: is_string($data['locale'] ?? null) ? $data['locale'] : 'fr',
            allowedReturnUrls: $this->strings($data['allowed_return_urls'] ?? []),
            allowSelfCancel: $data['allow_self_cancel'] ?? false,
            allowPlanChange: $data['allow_plan_change'] ?? false,
            paymentChannels: $this->strings($data['payment_channels'] ?? []),
            directLoginEnabled: $data['direct_login_enabled'] ?? false,
            directLoginSession: is_string($data['direct_login_session'] ?? null) ? $data['direct_login_session'] : null,
        ), TraceContext::correlationId());

        return new JsonResponse($this->body($saved));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(PortalSettingsData $s): array
    {
        return [
            'display_name' => $s->displayName,
            'logo_url' => $s->logoUrl,
            'primary_color' => $s->primaryColor,
            'support_url' => $s->supportUrl,
            'locale' => $s->locale,
            'allowed_return_urls' => $s->allowedReturnUrls,
            'allow_self_cancel' => $s->allowSelfCancel,
            'allow_plan_change' => $s->allowPlanChange,
            'payment_channels' => $s->paymentChannels,
            'direct_login_enabled' => $s->directLoginEnabled,
            'direct_login_session' => $s->directLoginSession,
        ];
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
