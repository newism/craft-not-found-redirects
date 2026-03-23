<?php

namespace newism\notfoundredirects\migrations;

use craft\db\Migration;
use craft\db\Table;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        // Drop in reverse FK dependency order
        $this->dropTableIfExists('{{%notfoundredirects_notes}}');
        $this->dropTableIfExists('{{%notfoundredirects_referrers}}');
        $this->dropTableIfExists('{{%notfoundredirects_404s}}');
        $this->dropTableIfExists('{{%notfoundredirects_redirects}}');

        return true;
    }

    protected function createTables(): void
    {
        // Redirects table (created first — referenced by FK from 404s)
        $this->createTable('{{%notfoundredirects_redirects}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->null()->defaultValue(null),
            'from' => $this->string(2000)->notNull(),
            'to' => $this->string(2000)->notNull()->defaultValue(''),
            'toType' => $this->string(10)->notNull()->defaultValue('url'),
            'toElementId' => $this->integer()->null()->defaultValue(null),
            'statusCode' => $this->integer()->notNull()->defaultValue(302),
            'priority' => $this->integer()->notNull()->defaultValue(0),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'startDate' => $this->dateTime()->null()->defaultValue(null),
            'endDate' => $this->dateTime()->null()->defaultValue(null),
            'systemGenerated' => $this->boolean()->notNull()->defaultValue(false),
            'elementId' => $this->integer()->null()->defaultValue(null),
            'createdById' => $this->integer()->null()->defaultValue(null),
            'hitCount' => $this->integer()->notNull()->defaultValue(0),
            'hitLastTime' => $this->dateTime()->null()->defaultValue(null),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // 404s table
        $this->createTable('{{%notfoundredirects_404s}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'uri' => $this->string(2000)->notNull(),
            'fullUrl' => $this->string(2000)->notNull(),
            'hitCount' => $this->integer()->notNull()->defaultValue(1),
            'hitLastTime' => $this->dateTime()->notNull(),
            'handled' => $this->boolean()->notNull()->defaultValue(false),
            'redirectId' => $this->integer()->null()->defaultValue(null),
            'source' => $this->string(50)->null()->defaultValue(null),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Notes table
        $this->createTable('{{%notfoundredirects_notes}}', [
            'id' => $this->primaryKey(),
            'redirectId' => $this->integer()->notNull(),
            'note' => $this->text()->notNull(),
            'systemGenerated' => $this->boolean()->notNull()->defaultValue(false),
            'createdById' => $this->integer()->null()->defaultValue(null),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Referrers audit table
        $this->createTable('{{%notfoundredirects_referrers}}', [
            'id' => $this->primaryKey(),
            'notFoundId' => $this->integer()->notNull(),
            'referrer' => $this->string(2000)->notNull(),
            'hitCount' => $this->integer()->notNull()->defaultValue(1),
            'hitLastTime' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    protected function createIndexes(): void
    {
        // Redirects indexes
        $this->createIndex(null, '{{%notfoundredirects_redirects}}', ['from'], false);
        $this->createIndex(null, '{{%notfoundredirects_redirects}}', ['siteId'], false);
        $this->createIndex(null, '{{%notfoundredirects_redirects}}', ['enabled'], false);
        $this->createIndex(null, '{{%notfoundredirects_redirects}}', ['priority'], false);
        $this->createIndex(null, '{{%notfoundredirects_redirects}}', ['systemGenerated'], false);
        $this->createIndex(null, '{{%notfoundredirects_redirects}}', ['toElementId'], false);
        $this->createIndex(null, '{{%notfoundredirects_redirects}}', ['elementId'], false);
        $this->createIndex(null, '{{%notfoundredirects_redirects}}', ['createdById'], false);

        // 404s indexes
        $this->createIndex(null, '{{%notfoundredirects_404s}}', ['uri', 'siteId'], true);
        $this->createIndex(null, '{{%notfoundredirects_404s}}', ['handled'], false);
        $this->createIndex(null, '{{%notfoundredirects_404s}}', ['siteId'], false);
        $this->createIndex(null, '{{%notfoundredirects_404s}}', ['redirectId'], false);

        // Notes indexes
        $this->createIndex(null, '{{%notfoundredirects_notes}}', ['redirectId'], false);

        // Referrers indexes
        $this->createIndex(null, '{{%notfoundredirects_referrers}}', ['notFoundId', 'referrer'], true);
        $this->createIndex(null, '{{%notfoundredirects_referrers}}', ['notFoundId'], false);
    }

    protected function addForeignKeys(): void
    {
        // Redirects -> Sites
        $this->addForeignKey(
            null,
            '{{%notfoundredirects_redirects}}',
            ['siteId'],
            Table::SITES,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Redirects -> Users (created by)
        $this->addForeignKey(
            null,
            '{{%notfoundredirects_redirects}}',
            ['createdById'],
            Table::USERS,
            ['id'],
            'SET NULL',
            'CASCADE'
        );

        // Redirects -> Elements (destination entry)
        $this->addForeignKey(
            null,
            '{{%notfoundredirects_redirects}}',
            ['toElementId'],
            Table::ELEMENTS,
            ['id'],
            'SET NULL',
            'CASCADE'
        );

        // Notes -> Redirects (CASCADE delete)
        $this->addForeignKey(
            null,
            '{{%notfoundredirects_notes}}',
            ['redirectId'],
            '{{%notfoundredirects_redirects}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Notes -> Users (created by)
        $this->addForeignKey(
            null,
            '{{%notfoundredirects_notes}}',
            ['createdById'],
            Table::USERS,
            ['id'],
            'SET NULL',
            'CASCADE'
        );

        // 404s -> Sites
        $this->addForeignKey(
            null,
            '{{%notfoundredirects_404s}}',
            ['siteId'],
            Table::SITES,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // 404s -> Redirects (SET NULL on delete so 404 records persist)
        $this->addForeignKey(
            null,
            '{{%notfoundredirects_404s}}',
            ['redirectId'],
            '{{%notfoundredirects_redirects}}',
            ['id'],
            'SET NULL',
            'CASCADE'
        );

        // Referrers -> 404s (CASCADE delete)
        $this->addForeignKey(
            null,
            '{{%notfoundredirects_referrers}}',
            ['notFoundId'],
            '{{%notfoundredirects_404s}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );
    }
}
