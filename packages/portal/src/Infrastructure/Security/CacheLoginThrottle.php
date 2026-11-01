<?php

declare(strict_types=1);

namespace Core\Portal\Infrastructure\Security;

use Core\Portal\Domain\Exception\LoginThrottleUnavailable;
use Core\Portal\Domain\Port\LoginThrottle;
use Illuminate\Cache\RateLimiter;
use Throwable;

/**
 * ADR-0085 §1 — the hourly budgets of the login by code, counted in the
 * cache with Laravel's rate limiter.
 *
 * Fail closed: any error of the counter (a cache that is down, a timeout)
 * becomes `LoginThrottleUnavailable`; nothing here ever answers "allowed"
 * because it could not count. The keys carry only hashes and addresses,
 * never a phone number.
 */
final readonly class CacheLoginThrottle implements LoginThrottle
{
    private const WINDOW_SECONDS = 3600;

    public function __construct(
        private RateLimiter $limiter,
        private int $perPhone = 5,
        private int $perIp = 30,
        private int $verificationsPerIp = 60,
        private int $projectHourlyCap = 200,
    ) {}

    public function admitRequest(string $projectId, string $contactHash, ?string $ip): bool
    {
        return $this->spend([
            ['portal-login:phone:'.$projectId.':'.$contactHash, $this->perPhone],
            ['portal-login:ip:'.($ip ?? 'unknown'), $this->perIp],
        ]);
    }

    public function admitVerification(string $projectId, ?string $ip): bool
    {
        return $this->spend([['portal-login:verify-ip:'.($ip ?? 'unknown'), $this->verificationsPerIp]]);
    }

    public function admitProjectSend(string $projectId): bool
    {
        return $this->spend([['portal-login:project:'.$projectId, $this->projectHourlyCap]]);
    }

    /**
     * Spends one unit of every budget and tells whether all of them held.
     * The unit is taken first (an atomic increment) and compared after, so
     * two requests racing for the last unit cannot both pass; a refused
     * request still counts, which only makes a flood hit its wall sooner.
     *
     * @param list<array{0: string, 1: int}> $budgets key and maximum per hour
     */
    private function spend(array $budgets): bool
    {
        $within = true;

        try {
            foreach ($budgets as [$key, $max]) {
                $used = $this->limiter->hit($key, self::WINDOW_SECONDS);

                if ($used > $max) {
                    $within = false;
                }
            }
        } catch (Throwable $e) {
            throw new LoginThrottleUnavailable('The login throttle cannot be read.', 0, $e);
        }

        return $within;
    }
}
