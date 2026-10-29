<?php

declare(strict_types=1);

namespace Core\Export;

use Core\Export\Application\UseCase\DownloadExportUseCase;
use Core\Export\Application\UseCase\GetExportUseCase;
use Core\Export\Application\UseCase\ListExportsUseCase;
use Core\Export\Application\UseCase\PurgeExpiredExportsUseCase;
use Core\Export\Application\UseCase\RequestExportUseCase;
use Core\Export\Application\UseCase\RunExportUseCase;
use Core\Export\Contracts\DownloadExport;
use Core\Export\Contracts\GetExport;
use Core\Export\Contracts\ListExports;
use Core\Export\Contracts\PurgeExpiredExports;
use Core\Export\Contracts\RequestExport;
use Core\Export\Contracts\RunExport;
use Core\Export\Contracts\SignedDownloadLinks;
use Core\Export\Domain\Port\ExportQueue;
use Core\Export\Domain\Port\ExportRepository;
use Core\Export\Domain\Port\ExportStore;
use Core\Export\Infrastructure\Persistence\Eloquent\EloquentExportRepository;
use Core\Export\Infrastructure\Queue\LaravelExportQueue;
use Core\Export\Infrastructure\Storage\EncryptedZipExportStore;
use Core\Export\Presentation\Console\PurgeExpiredExportsCommand;
use Core\Export\Presentation\Support\SignedExportLinks;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `export` bounded context (ADR-0088 §3, ADR-0101):
 * the asynchronous, encrypted, time-limited export of a project.
 */
final class ExportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ExportRepository::class, EloquentExportRepository::class);
        $this->app->bind(ExportStore::class, EncryptedZipExportStore::class);
        $this->app->bind(ExportQueue::class, LaravelExportQueue::class);

        $this->app->bind(RequestExport::class, RequestExportUseCase::class);
        $this->app->bind(RunExport::class, RunExportUseCase::class);
        $this->app->bind(GetExport::class, GetExportUseCase::class);
        $this->app->bind(ListExports::class, ListExportsUseCase::class);
        $this->app->bind(PurgeExpiredExports::class, PurgeExpiredExportsUseCase::class);
        $this->app->bind(DownloadExport::class, DownloadExportUseCase::class);
        $this->app->bind(SignedDownloadLinks::class, SignedExportLinks::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::middleware('api')->group(__DIR__.'/../routes/api.php');
        Route::group([], __DIR__.'/../routes/download.php');

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeExpiredExportsCommand::class]);
        }
    }
}
