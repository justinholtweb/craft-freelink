<?php

namespace justinholtweb\freelink\migrations;

use Craft;
use craft\db\Migration;
use justinholtweb\freelink\jobs\IndexLinks;

/**
 * 5.3: the link index (`freelink_index`) and stored link check results (`freelink_checks`).
 *
 * Both hold derived data only. The index is filled by a queued IndexLinks job.
 */
class m261119_000000_link_index_and_checks extends Migration
{
    public function safeUp(): bool
    {
        Install::createIndexTable($this);
        Install::createChecksTable($this);

        Craft::$app->getQueue()->push(new IndexLinks());

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Install::TABLE_CHECKS);
        $this->dropTableIfExists(Install::TABLE_INDEX);

        return true;
    }
}
