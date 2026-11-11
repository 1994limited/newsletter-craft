<?php

namespace nineteenninetyfour\newsletter\elements\exporters;

use Craft;
use craft\base\ElementExporter;
use craft\elements\db\ElementQueryInterface;
use nineteenninetyfour\newsletter\elements\db\SubscriberQuery;
use nineteenninetyfour\newsletter\Plugin;

/**
 * "Export…" on the Newsletter page: the subscribers shown (the source,
 * search and selection), with the same columns as the Download CSV button.
 */
class SubscribersCsv extends ElementExporter
{
    public static function displayName(): string
    {
        return Craft::t('newsletter', 'Subscribers');
    }

    public function export(ElementQueryInterface $query): mixed
    {
        /** @var SubscriberQuery $query */
        $service = Plugin::getInstance()->subscribers;
        $headings = $service->csvHeadings();
        $rows = [];

        foreach ($service->csvRows($query) as $row) {
            $rows[] = array_combine($headings, $row);
        }

        return $rows;
    }
}
