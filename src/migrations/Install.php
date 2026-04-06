<?php

namespace newism\notfoundredirects\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use newism\notfoundredirects\db\Table;

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
        $this->dropTableIfExists(Table::NOTES);
        $this->dropTableIfExists(Table::REFERRERS);
        $this->dropTableIfExists(Table::NOT_FOUND_URIS);
        $this->dropTableIfExists(Table::REDIRECTS);

        return true;
    }

    protected function createTables(): void
    {
        // Redirects table (created first — referenced by FK from 404s)
        $this->createTable(Table::REDIRECTS, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->null()->defaultValue(null),
            'from' => $this->string(500)->notNull(),
            'to' => $this->string(500)->notNull()->defaultValue(''),
            'toType' => $this->string(10)->notNull()->defaultValue('url'),
            'toElementId' => $this->integer()->null()->defaultValue(null),
            'statusCode' => $this->integer()->notNull()->defaultValue(302),
            'priority' => $this->integer()->notNull()->defaultValue(0),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'startDate' => $this->dateTime()->null()->defaultValue(null),
            'endDate' => $this->dateTime()->null()->defaultValue(null),
            'regexMatch' => $this->boolean()->notNull()->defaultValue(false),
            'systemGenerated' => $this->boolean()->notNull()->defaultValue(false),
            'elementId' => $this->integer()->null()->defaultValue(null),
            'hitCount' => $this->integer()->notNull()->defaultValue(0),
            'hitLastTime' => $this->dateTime()->null()->defaultValue(null),
            'createdById' => $this->integer()->null()->defaultValue(null),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // 404s table
        $this->createTable(Table::NOT_FOUND_URIS, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'uri' => $this->string(500)->notNull(),
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
        $this->createTable(Table::NOTES, [
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
        $this->createTable(Table::REFERRERS, [
            'id' => $this->primaryKey(),
            'notFoundId' => $this->integer()->notNull(),
            'referrer' => $this->string(500)->notNull(),
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
        $this->createIndex(null, Table::REDIRECTS, ['from'], false);
        $this->createIndex(null, Table::REDIRECTS, ['siteId'], false);
        $this->createIndex(null, Table::REDIRECTS, ['enabled'], false);
        $this->createIndex(null, Table::REDIRECTS, ['priority'], false);
        $this->createIndex(null, Table::REDIRECTS, ['systemGenerated'], false);
        $this->createIndex(null, Table::REDIRECTS, ['toElementId'], false);
        $this->createIndex(null, Table::REDIRECTS, ['elementId'], false);
        $this->createIndex(null, Table::REDIRECTS, ['createdById'], false);

        // 404s indexes
        $this->createIndex(null, Table::NOT_FOUND_URIS, ['uri', 'siteId'], true);
        $this->createIndex(null, Table::NOT_FOUND_URIS, ['handled'], false);
        $this->createIndex(null, Table::NOT_FOUND_URIS, ['siteId'], false);
        $this->createIndex(null, Table::NOT_FOUND_URIS, ['redirectId'], false);

        // Notes indexes
        $this->createIndex(null, Table::NOTES, ['redirectId'], false);

        // Referrers indexes
        $this->createIndex(null, Table::REFERRERS, ['notFoundId', 'referrer'], true);
        $this->createIndex(null, Table::REFERRERS, ['notFoundId'], false);
    }

    protected function addForeignKeys(): void
    {
        // Redirects -> Sites
        $this->addForeignKey(
            null,
            Table::REDIRECTS,
            ['siteId'],
            CraftTable::SITES,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Redirects -> Users (created by)
        $this->addForeignKey(
            null,
            Table::REDIRECTS,
            ['createdById'],
            CraftTable::USERS,
            ['id'],
            'SET NULL',
            'CASCADE'
        );

        // Redirects -> Elements (destination entry)
        $this->addForeignKey(
            null,
            Table::REDIRECTS,
            ['toElementId'],
            CraftTable::ELEMENTS,
            ['id'],
            'SET NULL',
            'CASCADE'
        );

        // Notes -> Redirects (CASCADE delete)
        $this->addForeignKey(
            null,
            Table::NOTES,
            ['redirectId'],
            Table::REDIRECTS,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Notes -> Users (created by)
        $this->addForeignKey(
            null,
            Table::NOTES,
            ['createdById'],
            CraftTable::USERS,
            ['id'],
            'SET NULL',
            'CASCADE'
        );

        // 404s -> Sites
        $this->addForeignKey(
            null,
            Table::NOT_FOUND_URIS,
            ['siteId'],
            CraftTable::SITES,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // 404s -> Redirects (SET NULL on delete so 404 records persist)
        $this->addForeignKey(
            null,
            Table::NOT_FOUND_URIS,
            ['redirectId'],
            Table::REDIRECTS,
            ['id'],
            'SET NULL',
            'CASCADE'
        );

        // Referrers -> 404s (CASCADE delete)
        $this->addForeignKey(
            null,
            Table::REFERRERS,
            ['notFoundId'],
            Table::NOT_FOUND_URIS,
            ['id'],
            'CASCADE',
            'CASCADE'
        );
    }
}
