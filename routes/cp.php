<?php

declare(strict_types=1);

use CraftCms\Cms\Http\Middleware\RequireAdmin;
use CraftCms\Cms\Http\Middleware\RequireAdminChanges;
use Illuminate\Support\Facades\Route;
use JustinHoltWeb\FreeLink\Http\Controllers\LinksController;
use JustinHoltWeb\FreeLink\Http\Controllers\MigrationsController;
use JustinHoltWeb\FreeLink\Plugin;

use function CraftCms\Cms\cp_url;

// The CP group already applies `auth`, `can:accessCp`, CSRF and the cpTrigger prefix. Plugin routes
// don't check `accessPlugin-freelink` by themselves, so every route here does.

Route::middleware('can:accessPlugin-freelink')->group(function () {
    Route::get('freelink', [LinksController::class, 'sectionIndex'])->name('freelink');

    Route::middleware('can:'.Plugin::PERMISSION_VIEW_LINKS)->group(function () {
        Route::get('freelink/links', [LinksController::class, 'index'])->name('freelink.links');
        Route::get('freelink/broken', [LinksController::class, 'broken'])->name('freelink.broken');
    });

    Route::post('freelink/links/queue-check', [LinksController::class, 'queueCheck'])
        ->middleware('can:'.Plugin::PERMISSION_CHECK_LINKS)
        ->name('freelink.links.queue-check');

    Route::middleware(RequireAdmin::class)->group(function () {
        Route::post('freelink/links/reindex', [LinksController::class, 'reindex'])->name('freelink.links.reindex');
        Route::get('freelink/migrations', [MigrationsController::class, 'index'])->name('freelink.migrations');
        Route::post('freelink/migrations/run', [MigrationsController::class, 'run'])
            ->middleware(RequireAdminChanges::class)
            ->name('freelink.migrations.run');
    });
});

// FreeLink 5.2's template-routed migration page. Route::redirect() would drop the cpTrigger.
Route::get('freelink/migrate', fn () => redirect(cp_url('freelink/migrations'), 301));
Route::get('freelink/migrate/index', fn () => redirect(cp_url('freelink/migrations'), 301));
