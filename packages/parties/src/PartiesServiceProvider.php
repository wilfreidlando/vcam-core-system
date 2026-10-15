<?php

declare(strict_types=1);

namespace Core\Parties;

use Core\Parties\Application\UseCase\FindOrCreateParty;
use Core\Parties\Application\UseCase\FindPartyByExternalRef;
use Core\Parties\Contracts\PartyFinder;
use Core\Parties\Contracts\PartyLookup;
use Core\Parties\Domain\Port\PartyRepository;
use Core\Parties\Infrastructure\Persistence\Eloquent\EloquentPartyRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Bounded context: parties
 * Responsibility: Party references (lazy-created, no PII) and their attachments.
 * Delivery lot: Lot 2
 */
final class PartiesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PartyRepository::class, EloquentPartyRepository::class);
        $this->app->bind(PartyFinder::class, FindOrCreateParty::class);
        $this->app->bind(PartyLookup::class, FindPartyByExternalRef::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
