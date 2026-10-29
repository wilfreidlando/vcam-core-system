<?php

declare(strict_types=1);

namespace Core\Portal\Infrastructure\Persistence;

use Core\Portal\Domain\Model\PortalSettings;
use Core\Portal\Domain\Port\PortalSettingsRepository;
use Illuminate\Support\Facades\DB;

final class DatabasePortalSettingsRepository implements PortalSettingsRepository
{
    public function find(string $projectId): PortalSettings
    {
        $row = DB::table('portal_settings')->where('project_id', $projectId)->first();

        if ($row === null) {
            return PortalSettings::defaultsFor($projectId);
        }

        $r = Row::of($row);

        return new PortalSettings(
            $projectId,
            $r->string('display_name'),
            $r->nullableString('logo_url'),
            $r->string('primary_color'),
            $r->nullableString('support_url'),
            $r->string('locale'),
            $this->strings($r->nullableString('allowed_return_urls')),
            $r->bool('allow_self_cancel'),
            $r->bool('allow_plan_change'),
            $this->strings($r->nullableString('payment_channels')),
            $r->bool('direct_login_enabled'),
            $r->nullableString('direct_login_session'),
            $this->strings($r->nullableString('allowed_asset_origins')),
        );
    }

    public function save(PortalSettings $settings): void
    {
        $attributes = [
            'display_name' => $settings->displayName,
            'logo_url' => $settings->logoUrl,
            'primary_color' => $settings->primaryColor,
            'support_url' => $settings->supportUrl,
            'locale' => $settings->locale,
            'allowed_return_urls' => json_encode($settings->allowedReturnUrls),
            'allow_self_cancel' => $settings->allowSelfCancel,
            'allow_plan_change' => $settings->allowPlanChange,
            'payment_channels' => json_encode($settings->paymentChannels),
            'direct_login_enabled' => $settings->directLoginEnabled,
            'direct_login_session' => $settings->directLoginSession,
            'allowed_asset_origins' => json_encode($settings->allowedAssetOrigins),
            'updated_at' => now(),
        ];

        $updated = DB::table('portal_settings')->where('project_id', $settings->projectId)->update($attributes);

        if ($updated === 0) {
            DB::table('portal_settings')->insertOrIgnore(['project_id' => $settings->projectId, 'created_at' => now()] + $attributes);
            DB::table('portal_settings')->where('project_id', $settings->projectId)->update($attributes);
        }
    }

    /**
     * @return list<string>
     */
    private function strings(?string $json): array
    {
        $decoded = $json === null ? [] : json_decode($json, true);
        $items = [];

        foreach (is_array($decoded) ? $decoded : [] as $item) {
            if (is_string($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
