<?php

declare(strict_types=1);

namespace Core\Portal\Infrastructure\Persistence;

use Core\Portal\Domain\Model\PortalSession;
use Core\Portal\Domain\Port\PortalSessionRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class DatabasePortalSessionRepository implements PortalSessionRepository
{
    public function create(PortalSession $session, string $tokenHash, ?string $createdByIp): void
    {
        DB::table('portal_sessions')->insert([
            'id' => $session->id,
            'project_id' => $session->projectId,
            'customer_id' => $session->customerId,
            'customer_ref' => $session->customerRef,
            'flow' => $session->flow,
            'flow_ref' => $session->flowRef,
            'return_url' => $session->returnUrl,
            'token_hash' => $tokenHash,
            'token_expires_at' => $this->ts($session->tokenExpiresAt),
            'created_by_ip' => $createdByIp,
            'created_at' => $this->ts($session->createdAt),
        ]);
    }

    public function exchange(string $tokenHash, string $sessionHash, DateTimeImmutable $now, DateTimeImmutable $sessionExpiresAt): ?PortalSession
    {
        // The claim: one conditional UPDATE. Whoever updates the row owns
        // the link; the others find nothing left to update.
        $claimed = DB::table('portal_sessions')
            ->where('token_hash', $tokenHash)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('token_expires_at', '>', $this->ts($now))
            ->update([
                'consumed_at' => $this->ts($now),
                'session_hash' => $sessionHash,
                'last_seen_at' => $this->ts($now),
                'session_expires_at' => $this->ts($sessionExpiresAt),
            ]);

        if ($claimed !== 1) {
            return null;
        }

        $row = DB::table('portal_sessions')->where('token_hash', $tokenHash)->first();

        return $row === null ? null : $this->toDomain($row);
    }

    public function findLive(string $sessionHash, DateTimeImmutable $now, DateTimeImmutable $idleCutoff): ?PortalSession
    {
        $row = DB::table('portal_sessions')
            ->where('session_hash', $sessionHash)
            ->whereNull('revoked_at')
            ->where('session_expires_at', '>', $this->ts($now))
            ->where('last_seen_at', '>', $this->ts($idleCutoff))
            ->first();

        return $row === null ? null : $this->toDomain($row);
    }

    public function touch(string $id, DateTimeImmutable $now): void
    {
        // One write a minute at most: the idle limit is thirty minutes, and a
        // page that loads six assets must not rewrite its row six times.
        DB::table('portal_sessions')->where('id', $id)
            ->where('last_seen_at', '<', $this->ts($now->modify('-60 seconds')))
            ->update(['last_seen_at' => $this->ts($now)]);
    }

    public function revoke(string $id, DateTimeImmutable $now): void
    {
        DB::table('portal_sessions')->where('id', $id)->update(['revoked_at' => $this->ts($now)]);
    }

    private function toDomain(object $row): PortalSession
    {
        $r = Row::of($row);

        return new PortalSession(
            $r->string('id'),
            $r->string('project_id'),
            $r->string('customer_id'),
            $r->string('customer_ref'),
            $r->string('flow'),
            $r->nullableString('flow_ref'),
            $r->nullableString('return_url'),
            new DateTimeImmutable($r->string('token_expires_at')),
            new DateTimeImmutable($r->string('created_at')),
            ($expires = $r->nullableString('session_expires_at')) === null ? null : new DateTimeImmutable($expires),
        );
    }

    private function ts(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
