<?php

declare(strict_types=1);

namespace Core\Portal\Infrastructure\Persistence;

use Core\Portal\Domain\Model\PortalLoginCode;
use Core\Portal\Domain\Port\PortalLoginCodeRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class DatabasePortalLoginCodeRepository implements PortalLoginCodeRepository
{
    public function issue(PortalLoginCode $code, ?string $createdByIp, DateTimeImmutable $now): void
    {
        DB::transaction(function () use ($code, $createdByIp, $now): void {
            // One right code at a time: the older ones of this phone are retired.
            DB::table('portal_login_codes')
                ->where('project_id', $code->projectId)
                ->where('contact_hash', $code->contactHash)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $this->ts($now)]);

            DB::table('portal_login_codes')->insert([
                'id' => $code->id,
                'project_id' => $code->projectId,
                'customer_id' => $code->customerId,
                'customer_ref' => $code->customerRef,
                'contact_hash' => $code->contactHash,
                'code_hash' => $code->codeHash,
                'attempts' => 0,
                'expires_at' => $this->ts($code->expiresAt),
                'created_by_ip' => $createdByIp,
                'created_at' => $this->ts($code->createdAt),
            ]);
        });
    }

    public function lastIssuedAt(string $projectId, string $contactHash): ?DateTimeImmutable
    {
        $row = DB::table('portal_login_codes')
            ->where('project_id', $projectId)
            ->where('contact_hash', $contactHash)
            ->orderByDesc('created_at')
            ->first();

        return $row === null ? null : new DateTimeImmutable(Row::of($row)->string('created_at'));
    }

    public function findLive(string $projectId, string $contactHash, DateTimeImmutable $now): ?PortalLoginCode
    {
        $row = DB::table('portal_login_codes')
            ->where('project_id', $projectId)
            ->where('contact_hash', $contactHash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $this->ts($now))
            ->where('attempts', '<', PortalLoginCode::MAX_ATTEMPTS)
            ->orderByDesc('created_at')
            ->first();

        if ($row === null) {
            return null;
        }

        $r = Row::of($row);

        return new PortalLoginCode(
            $r->string('id'),
            $r->string('project_id'),
            $r->string('customer_id'),
            $r->string('customer_ref'),
            $r->string('contact_hash'),
            $r->string('code_hash'),
            (int) $r->string('attempts'),
            new DateTimeImmutable($r->string('expires_at')),
            new DateTimeImmutable($r->string('created_at')),
        );
    }

    public function registerAttempt(string $id, DateTimeImmutable $now): bool
    {
        return DB::table('portal_login_codes')
            ->where('id', $id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $this->ts($now))
            ->where('attempts', '<', PortalLoginCode::MAX_ATTEMPTS)
            ->increment('attempts') === 1;
    }

    public function consume(string $id, DateTimeImmutable $now): bool
    {
        return DB::table('portal_login_codes')
            ->where('id', $id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $this->ts($now))
            ->update(['consumed_at' => $this->ts($now)]) === 1;
    }

    private function ts(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
