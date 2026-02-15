<?php

namespace justinholtweb\freelink\migrations;

use craft\db\Migration;

class Install extends Migration
{
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

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%freelink_migrations}}');
        $this->dropTableIfExists('{{%freelink_links}}');

        return true;
    }
}
