<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Integration\Infrastructure\Persistence;

use Core\Notification\Domain\Model\ChannelSession;
use Core\Notification\Domain\Port\ChannelSessionRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EloquentChannelSessionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_A = '01a0a1cc-0000-7000-8000-00000000000a';

    private const PROJECT_B = '01a0a1cc-0000-7000-8000-00000000000b';

    #[Test]
    public function a_global_session_is_unique_by_name_despite_the_null_owner(): void
    {
        $repository = app(ChannelSessionRepository::class);

        $repository->save($this->registered(null, 'shared'));
        $repository->save($this->registered(null, 'shared'));

        // The port is idempotent; the partial unique index is what protects
        // against a concurrent duplicate (NULLs are distinct in a plain
        // unique index).
        self::assertSame(1, DB::table('notification_channel_sessions')->count());

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('notification_channel_sessions')->insert([
            'id' => (string) Str::uuid(), 'project_id' => null, 'channel' => 'whatsapp',
            'session_ref' => 'shared', 'created_at' => now(),
        ]);
    }

    #[Test]
    public function the_same_name_can_exist_for_two_projects_and_globally(): void
    {
        $repository = app(ChannelSessionRepository::class);

        $repository->save($this->registered(self::PROJECT_A, 'main'));
        $repository->save($this->registered(self::PROJECT_B, 'main'));
        $repository->save($this->registered(null, 'main'));

        self::assertSame(3, DB::table('notification_channel_sessions')->count());
        self::assertSame(self::PROJECT_A, $repository->find(self::PROJECT_A, 'whatsapp', 'main')?->projectId);
        self::assertNull($repository->find(null, 'whatsapp', 'main')?->projectId);
    }

    #[Test]
    public function usable_for_prefers_the_projects_own_session_then_a_global_one_and_never_another_projects(): void
    {
        $repository = app(ChannelSessionRepository::class);
        $repository->save($this->registered(self::PROJECT_A, 'own'));
        $repository->save($this->registered(null, 'own'));
        $repository->save($this->registered(null, 'shared'));
        $repository->save($this->registered(self::PROJECT_B, 'theirs'));

        self::assertSame(self::PROJECT_A, $repository->findUsableFor(self::PROJECT_A, 'whatsapp', 'own')?->projectId);
        self::assertNull($repository->findUsableFor(self::PROJECT_A, 'whatsapp', 'shared')?->projectId);
        self::assertNotNull($repository->findUsableFor(self::PROJECT_A, 'whatsapp', 'shared'));
        self::assertNull($repository->findUsableFor(self::PROJECT_A, 'whatsapp', 'theirs'));
    }

    #[Test]
    public function listing_returns_only_the_requested_owners_sessions(): void
    {
        $repository = app(ChannelSessionRepository::class);
        $repository->save($this->registered(self::PROJECT_A, 'a1'));
        $repository->save($this->registered(self::PROJECT_B, 'b1'));
        $repository->save($this->registered(null, 'g1'));

        $names = fn (?string $owner): array => array_map(
            static fn (ChannelSession $s): string => $s->sessionRef,
            $repository->findAllFor($owner, 'whatsapp'),
        );

        self::assertSame(['a1'], $names(self::PROJECT_A));
        self::assertSame(['g1'], $names(null));
    }

    private function registered(?string $projectId, string $ref): ChannelSession
    {
        return new ChannelSession((string) Str::uuid(), $projectId, 'whatsapp', $ref, new DateTimeImmutable);
    }
}
