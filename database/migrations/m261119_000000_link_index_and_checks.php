<?php

declare(strict_types=1);

use CraftCms\Cms\Database\Migration;
use Illuminate\Support\Facades\Schema;
use JustinHoltWeb\FreeLink\Database\Migrations\Install;
use JustinHoltWeb\FreeLink\Database\Tables;
use JustinHoltWeb\FreeLink\Jobs\IndexLinks;

/**
 * FreeLink 5.3: the link index (`freelink_index`) and stored link check results (`freelink_checks`).
 *
 * Kept under its Craft 5 name so sites that ran it on FreeLink 5.3 see it as applied. Both tables
 * hold derived data only; the index is filled by a queued IndexLinks job.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! class_exists(Install::class)) {
            require_once __DIR__.'/Install.php';
        }

        Install::createIndexTable();
        Install::createChecksTable();

        IndexLinks::dispatch();
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::CHECKS);
        Schema::dropIfExists(Tables::INDEX);
    }
};
