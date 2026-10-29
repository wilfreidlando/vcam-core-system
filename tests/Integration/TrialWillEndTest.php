<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Billing\Application\UseCase\AdvanceSubscriptionsUseCase;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;
use Tests\TestCase;

/** ADR-0072 §9 — the project is told once, three days before a trial ends. */
final class TrialWillEndTest extends TestCase
{
    use RefreshDatabase;

    private object $clock;

    #[Test]
    public function the_warning_leaves_once_three_days_before_the_end_of_the_trial_and_never_again(): void
    {
        $this->clock = new class(new DateTimeImmutable('2026-11-01T08:00:00Z')) implements Clock
        {
            public function __construct(public DateTimeImmutable $moment) {}

            public function now(): DateTimeImmutable
            {
                return $this->moment;
            }
        };
        $clock = $this->clock;
        $this->app->bind(Clock::class, static fn (): Clock => $clock);

        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $projectId = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project->id;
        app(CreatePlan::class)->create(new CreatePlanCommand(projectId: $projectId, code: 'pro', name: 'Pro', description: null, visibility: 'public', sortOrder: 0, actor: 'test', correlationId: null));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId, planCode: 'pro', priceMinor: 25_000, currency: 'XAF', intervalUnit: 'month', intervalCount: 1,
            trialDays: 0, graceDays: 7, graceAccess: true, features: [], actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(projectId: $projectId, planCode: 'pro', version: $version->version, actor: 'test', correlationId: null));
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: 'AE0001', displayName: 'Client', email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
        app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId, customerRef: 'AE0001', planCode: 'pro', trialDays: 14, startAt: null,
            idempotencyKey: 'sub-1', actor: 'test', correlationId: null,
        ));

        $warnings = static fn (): int => DB::table('event_outbox')->where('type', 'subscription.trial_will_end')->count();
        $advance = static fn () => app(AdvanceSubscriptionsUseCase::class)->advance();

        // Day 5 of 14: eight days left, nothing to say.
        $this->goTo('2026-11-06T08:00:00Z');
        $advance();
        self::assertSame(0, $warnings());

        // Day 11 (exactly three days left): the warning leaves — and only once, however many passes follow.
        $this->goTo('2026-11-12T08:00:01Z');
        $advance();
        $advance();
        $this->goTo('2026-11-13T08:00:00Z');
        $advance();
        self::assertSame(1, $warnings());
    }

    private function goTo(string $moment): void
    {
        (new ReflectionObject($this->clock))->getProperty('moment')->setValue($this->clock, new DateTimeImmutable($moment));
    }
}
