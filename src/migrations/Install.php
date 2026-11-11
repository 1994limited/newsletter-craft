<?php

namespace nineteenninetyfour\newsletter\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * The list: one row per email address, alongside its element.
 */
class Install extends Migration
{
    public const SUBSCRIBERS = '{{%newsletter_subscribers}}';

    public function safeUp(): bool
    {
        if (! $this->db->tableExists(self::SUBSCRIBERS)) {
            $this->createTable(self::SUBSCRIBERS, [
                'id' => $this->integer()->notNull(),
                'email' => $this->string(254)->notNull(),
                'name' => $this->string(),
                'subscriberStatus' => $this->string(20)->notNull()->defaultValue('pending'),
                'subscriberSiteId' => $this->integer(),
                'consent' => $this->text(),
                'sourceUrl' => $this->string(500),
                'token' => $this->string(64)->notNull(),
                'dateConfirmationSent' => $this->dateTime(),
                'dateConfirmed' => $this->dateTime(),
                'dateUnsubscribed' => $this->dateTime(),
                'PRIMARY KEY([[id]])',
            ]);

            $this->createIndex(null, self::SUBSCRIBERS, ['email'], true);
            $this->createIndex(null, self::SUBSCRIBERS, ['token'], true);
            $this->createIndex(null, self::SUBSCRIBERS, ['subscriberStatus']);
            $this->addForeignKey(null, self::SUBSCRIBERS, ['id'], Table::ELEMENTS, ['id'], 'CASCADE');
            $this->addForeignKey(null, self::SUBSCRIBERS, ['subscriberSiteId'], Table::SITES, ['id'], 'SET NULL');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->delete(Table::ELEMENTS, ['type' => 'nineteenninetyfour\\newsletter\\elements\\Subscriber']);
        $this->dropTableIfExists(self::SUBSCRIBERS);

        return true;
    }
}
