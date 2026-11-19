<?php

namespace justinholtweb\freelink\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public const TABLE_INDEX = '{{%freelink_index}}';
    public const TABLE_CHECKS = '{{%freelink_checks}}';

    public function safeUp(): bool
    {
        $this->createTable('{{%freelink_links}}', [
            'id' => $this->primaryKey(),
            'fieldId' => $this->integer()->notNull(),
            'ownerId' => $this->integer()->notNull(),
            'ownerSiteId' => $this->integer()->notNull(),
            'sortOrder' => $this->smallInteger()->notNull()->defaultValue(0),
            'targetId' => $this->integer()->null(),
            'targetSiteId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(
            'idx_freelink_links_field_owner',
            '{{%freelink_links}}',
            ['fieldId', 'ownerId', 'ownerSiteId'],
        );

        $this->createIndex(
            'idx_freelink_links_target',
            '{{%freelink_links}}',
            ['targetId'],
        );

        $this->addForeignKey(
            'fk_freelink_links_fieldId',
            '{{%freelink_links}}',
            'fieldId',
            '{{%fields}}',
            'id',
            'CASCADE',
        );

        $this->addForeignKey(
            'fk_freelink_links_ownerId',
            '{{%freelink_links}}',
            'ownerId',
            '{{%elements}}',
            'id',
            'CASCADE',
        );

        $this->addForeignKey(
            'fk_freelink_links_ownerSiteId',
            '{{%freelink_links}}',
            'ownerSiteId',
            '{{%sites}}',
            'id',
            'CASCADE',
        );

        $this->addForeignKey(
            'fk_freelink_links_targetId',
            '{{%freelink_links}}',
            'targetId',
            '{{%elements}}',
            'id',
            'SET NULL',
        );

        $this->addForeignKey(
            'fk_freelink_links_targetSiteId',
            '{{%freelink_links}}',
            'targetSiteId',
            '{{%sites}}',
            'id',
            'SET NULL',
        );

        // Migration tracking table
        $this->createTable('{{%freelink_migrations}}', [
            'id' => $this->primaryKey(),
            'fieldId' => $this->integer()->notNull(),
            'sourcePlugin' => $this->string(50)->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'log' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        self::createIndexTable($this);
        self::createChecksTable($this);

        return true;
    }

    /**
     * `freelink_index`: one row per link in live content. Derived data, rebuilt by IndexLinks.
     */
    public static function createIndexTable(Migration $m): void
    {
        if ($m->db->tableExists(self::TABLE_INDEX)) {
            return;
        }

        $m->createTable(self::TABLE_INDEX, [
            'id' => $m->primaryKey(),
            'fieldId' => $m->integer()->notNull(),
            'ownerId' => $m->integer()->notNull(),
            'ownerSiteId' => $m->integer()->notNull(),
            'rootOwnerId' => $m->integer()->notNull(),
            'sortOrder' => $m->smallInteger()->notNull()->defaultValue(0),
            'type' => $m->string(64)->notNull(),
            'isElement' => $m->boolean()->notNull()->defaultValue(false),
            'targetId' => $m->integer()->null(),
            'targetSiteId' => $m->integer()->null(),
            'url' => $m->text()->null(),
            'urlHash' => $m->char(40)->null(),
            'host' => $m->string(255)->null(),
            'label' => $m->string(255)->null(),
            'dateCreated' => $m->dateTime()->notNull(),
            'dateUpdated' => $m->dateTime()->notNull(),
            'uid' => $m->uid(),
        ]);

        $m->createIndex(null, self::TABLE_INDEX, ['fieldId']);
        $m->createIndex(null, self::TABLE_INDEX, ['ownerId', 'ownerSiteId']);
        $m->createIndex(null, self::TABLE_INDEX, ['rootOwnerId']);
        $m->createIndex(null, self::TABLE_INDEX, ['type']);
        $m->createIndex(null, self::TABLE_INDEX, ['targetId']);
        $m->createIndex(null, self::TABLE_INDEX, ['host']);
        $m->createIndex(null, self::TABLE_INDEX, ['urlHash']);

        $m->addForeignKey(null, self::TABLE_INDEX, ['fieldId'], '{{%fields}}', ['id'], 'CASCADE');
        $m->addForeignKey(null, self::TABLE_INDEX, ['ownerId'], '{{%elements}}', ['id'], 'CASCADE');
        $m->addForeignKey(null, self::TABLE_INDEX, ['ownerSiteId'], '{{%sites}}', ['id'], 'CASCADE');
        $m->addForeignKey(null, self::TABLE_INDEX, ['rootOwnerId'], '{{%elements}}', ['id'], 'CASCADE');
        $m->addForeignKey(null, self::TABLE_INDEX, ['targetId'], '{{%elements}}', ['id'], 'SET NULL');
        $m->addForeignKey(null, self::TABLE_INDEX, ['targetSiteId'], '{{%sites}}', ['id'], 'SET NULL');
    }

    /**
     * `freelink_checks`: the last HTTP check of each distinct URL.
     */
    public static function createChecksTable(Migration $m): void
    {
        if ($m->db->tableExists(self::TABLE_CHECKS)) {
            return;
        }

        $m->createTable(self::TABLE_CHECKS, [
            'id' => $m->primaryKey(),
            'urlHash' => $m->char(40)->notNull(),
            'url' => $m->text()->notNull(),
            'status' => $m->string(16)->notNull(),
            'httpCode' => $m->smallInteger()->null(),
            'finalUrl' => $m->text()->null(),
            'redirects' => $m->tinyInteger()->notNull()->defaultValue(0),
            'error' => $m->string(255)->null(),
            'checkedAt' => $m->dateTime()->notNull(),
            'dateCreated' => $m->dateTime()->notNull(),
            'dateUpdated' => $m->dateTime()->notNull(),
            'uid' => $m->uid(),
        ]);

        $m->createIndex(null, self::TABLE_CHECKS, ['urlHash'], true);
        $m->createIndex(null, self::TABLE_CHECKS, ['status']);
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE_CHECKS);
        $this->dropTableIfExists(self::TABLE_INDEX);
        $this->dropTableIfExists('{{%freelink_migrations}}');
        $this->dropTableIfExists('{{%freelink_links}}');

        return true;
    }
}
