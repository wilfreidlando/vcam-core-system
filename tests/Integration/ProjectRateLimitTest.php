<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** ADR-0085 §1 — a project's budget is its own: one project cannot starve another. */
final class ProjectRateLimitTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_project_over_its_budget_gets_a_429_with_the_delay_and_another_project_is_untouched(): void
    {
        config(['ratelimit.project_per_minute' => 3, 'ratelimit.key_per_minute' => 100]);
        $busy = $this->tokenOfNewProject('Occupé');
        $calm = $this->tokenOfNewProject('Calme');

        foreach ([2, 1, 0] as $remaining) {
            $this->getJson('/v1/whoami', ['Authorization' => 'Bearer '.$busy])
                ->assertOk()
                ->assertHeader('RateLimit-Limit', '3')
                ->assertHeader('RateLimit-Remaining', (string) $remaining);
        }

        $this->getJson('/v1/whoami', ['Authorization' => 'Bearer '.$busy])
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://errors.core-system.internal/rate-limit-exceeded')
            ->assertHeader('RateLimit-Remaining', '0');
        self::assertGreaterThan(0, (int) $this->getJson('/v1/whoami', ['Authorization' => 'Bearer '.$busy])->headers->get('Retry-After'));

        $this->getJson('/v1/whoami', ['Authorization' => 'Bearer '.$calm])->assertOk();
    }

    #[Test]
    public function a_per_project_override_raises_that_projects_budget_only(): void
    {
        config(['ratelimit.project_per_minute' => 1, 'ratelimit.key_per_minute' => 100]);
        $token = $this->tokenOfNewProject('Grand compte', override: 5);

        foreach (range(1, 5) as $_) {
            $this->getJson('/v1/whoami', ['Authorization' => 'Bearer '.$token])->assertOk();
        }
        $this->getJson('/v1/whoami', ['Authorization' => 'Bearer '.$token])->assertStatus(429);
    }

    #[Test]
    public function the_limiter_can_be_switched_off_and_never_blocks_when_it_is(): void
    {
        config(['ratelimit.enabled' => false, 'ratelimit.project_per_minute' => 1]);
        $token = $this->tokenOfNewProject('Libre');

        foreach (range(1, 4) as $_) {
            $this->getJson('/v1/whoami', ['Authorization' => 'Bearer '.$token])->assertOk()->assertHeaderMissing('RateLimit-Limit');
        }
    }

    private function tokenOfNewProject(string $name, ?int $override = null): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org '.$name), actor: 'service:test', correlationId: null);
        $projectId = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, $name), actor: 'service:test', correlationId: null)->project->id;

        if ($override !== null) {
            config(['ratelimit.projects.'.$projectId => ['project_per_minute' => $override]]);
        }

        return app(IssueApiKey::class)->issue(new IssueApiKeyCommand($projectId, ['*'], name: 'Test'), actor: 'service:test', correlationId: null)->plaintextToken;
    }
}
