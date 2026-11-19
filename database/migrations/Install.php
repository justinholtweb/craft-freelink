<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Database\Migrations;

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Database\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JustinHoltWeb\FreeLink\Database\Tables;

/**
 * FreeLink's tables on a fresh install. Sites upgrading from FreeLink 5.3 already have all four, so
 * each is only created when missing.
 */
class Install extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(Tables::LINKS)) {
            Schema::create(Tables::LINKS, function (Blueprint $t): void {
                $t->integer('id', true);
                $t->integer('fieldId');
                $t->integer('ownerId');
                $t->integer('ownerSiteId');
                $t->smallInteger('sortOrder')->default(0);
                $t->integer('targetId')->nullable();
                $t->integer('targetSiteId')->nullable();
                self::audit($t);
                $t->index(['fieldId', 'ownerId', 'ownerSiteId'], 'idx_freelink_links_field_owner');
                $t->index(['targetId'], 'idx_freelink_links_target');
                $t->foreign('fieldId', 'fk_freelink_links_fieldId')->references('id')->on(Table::FIELDS)->cascadeOnDelete();
                $t->foreign('ownerId', 'fk_freelink_links_ownerId')->references('id')->on(Table::ELEMENTS)->cascadeOnDelete();
                $t->foreign('ownerSiteId', 'fk_freelink_links_ownerSiteId')->references('id')->on(Table::SITES)->cascadeOnDelete();
                $t->foreign('targetId', 'fk_freelink_links_targetId')->references('id')->on(Table::ELEMENTS)->nullOnDelete();
                $t->foreign('targetSiteId', 'fk_freelink_links_targetSiteId')->references('id')->on(Table::SITES)->nullOnDelete();
            });
        }

        if (! Schema::hasTable(Tables::MIGRATIONS)) {
            Schema::create(Tables::MIGRATIONS, function (Blueprint $t): void {
                $t->integer('id', true);
                $t->integer('fieldId');
                $t->string('sourcePlugin', 50);
                $t->string('status', 20)->default('pending');
                $t->text('log')->nullable();
                self::audit($t);
            });
        }

        self::createIndexTable();
        self::createChecksTable();
    }

    /**
     * `freelink_index`: one row per link in live content. Derived data, rebuilt by IndexLinks.
     */
    public static function createIndexTable(): void
    {
        if (Schema::hasTable(Tables::INDEX)) {
            return;
        }

        Schema::create(Tables::INDEX, function (Blueprint $t): void {
            $t->integer('id', true);
            $t->integer('fieldId');
            $t->integer('ownerId');
            $t->integer('ownerSiteId');
            $t->integer('rootOwnerId');
            $t->smallInteger('sortOrder')->default(0);
            $t->string('type', 64);
            $t->boolean('isElement')->default(false);
            $t->integer('targetId')->nullable();
            $t->integer('targetSiteId')->nullable();
            $t->text('url')->nullable();
            $t->char('urlHash', 40)->nullable();
            $t->string('host', 255)->nullable();
            $t->string('label', 255)->nullable();
            self::audit($t);
            $t->index(['fieldId']);
            $t->index(['ownerId', 'ownerSiteId']);
            $t->index(['rootOwnerId']);
            $t->index(['type']);
            $t->index(['targetId']);
            $t->index(['host']);
            $t->index(['urlHash']);
            $t->foreign('fieldId')->references('id')->on(Table::FIELDS)->cascadeOnDelete();
            $t->foreign('ownerId')->references('id')->on(Table::ELEMENTS)->cascadeOnDelete();
            $t->foreign('ownerSiteId')->references('id')->on(Table::SITES)->cascadeOnDelete();
            $t->foreign('rootOwnerId')->references('id')->on(Table::ELEMENTS)->cascadeOnDelete();
            $t->foreign('targetId')->references('id')->on(Table::ELEMENTS)->nullOnDelete();
            $t->foreign('targetSiteId')->references('id')->on(Table::SITES)->nullOnDelete();
        });
    }

    /**
     * `freelink_checks`: the last HTTP check of each distinct URL.
     */
    public static function createChecksTable(): void
    {
        if (Schema::hasTable(Tables::CHECKS)) {
            return;
        }

        Schema::create(Tables::CHECKS, function (Blueprint $t): void {
            $t->integer('id', true);
            $t->char('urlHash', 40);
            $t->text('url');
            $t->string('status', 16);
            $t->smallInteger('httpCode')->nullable();
            $t->text('finalUrl')->nullable();
            $t->tinyInteger('redirects')->default(0);
            $t->string('error', 255)->nullable();
            $t->dateTime('checkedAt');
            self::audit($t);
            $t->unique(['urlHash']);
            $t->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::CHECKS);
        Schema::dropIfExists(Tables::INDEX);
        Schema::dropIfExists(Tables::MIGRATIONS);
        Schema::dropIfExists(Tables::LINKS);
    }

    private static function audit(Blueprint $t): void
    {
        $t->dateTime('dateCreated');
        $t->dateTime('dateUpdated');
        $t->char('uid', 36)->default('0');
    }
}
