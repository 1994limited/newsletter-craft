<?php

namespace nineteenninetyfour\newsletter\tests\unit;

use nineteenninetyfour\newsletter\elements\exporters\SubscribersCsv;
use nineteenninetyfour\newsletter\elements\Subscriber;
use nineteenninetyfour\newsletter\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class ExportTest extends TestCase
{
    /**
     * @return array<int, array<int, string>>
     */
    protected function csv(Response $response): array
    {
        $this->assertIsArray($response->stream);
        rewind($response->stream[0]);
        $content = stream_get_contents($response->stream[0]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $rows = [];
        $handle = fopen('php://memory', 'w+');
        fwrite($handle, substr($content, 3));
        rewind($handle);

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function test_download_csv_is_the_confirmed_list_by_default(): void
    {
        $this->settings(['askName' => true]);
        $this->subscribed('ada@example.com', 'Ada Reader');
        $this->subscribed('bo@example.com');
        $this->pending('waiting@example.com');
        $this->plugin()->subscribers->unsubscribe($this->subscribed('gone@example.com'));
        $this->actingAs();

        $rows = $this->csv($this->action('newsletter/export/csv', method: 'GET', json: false));

        $this->assertSame(['Email', 'Name', 'Status', 'Site', 'Signed up', 'Confirmed', 'Unsubscribed', 'Agreed to', 'Form page', 'Unsubscribe link'], $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame(['ada@example.com', 'Ada Reader', 'subscribed', 'default'], array_slice($rows[1], 0, 4));
        $this->assertSame('bo@example.com', $rows[2][0]);
        $this->assertNotSame('', $rows[1][5]);
        $this->assertSame('Email me the newsletter.', $rows[1][7]);
        $this->assertStringContainsString('newsletter/subscribe/unsubscribe&code=', $rows[1][9]);
    }

    public function test_download_csv_by_status(): void
    {
        $this->subscribed('ada@example.com');
        $this->pending('waiting@example.com');
        $this->actingAs();

        $all = $this->csv($this->action('newsletter/export/csv', ['status' => 'all'], 'GET', json: false));
        $this->assertCount(3, $all);

        $pending = $this->csv($this->action('newsletter/export/csv', ['status' => 'pending'], 'GET', json: false));
        $this->assertCount(2, $pending);
        $this->assertSame('waiting@example.com', $pending[1][0]);

        // Anything else: the confirmed list.
        $this->assertCount(2, $this->csv($this->action('newsletter/export/csv', ['status' => 'nonsense'], 'GET', json: false)));
    }

    public function test_cells_are_never_read_as_formulas(): void
    {
        $this->settings(['askName' => true]);
        $this->subscribed('ada@example.com', '=HYPERLINK("https://evil.example")');
        $this->actingAs();

        $rows = $this->csv($this->action('newsletter/export/csv', method: 'GET', json: false));

        $this->assertSame('\'=HYPERLINK("https://evil.example")', $rows[1][1]);
    }

    public function test_download_needs_permission(): void
    {
        $this->subscribed();
        $this->actingAs([]);

        $this->expectException(ForbiddenHttpException::class);
        $this->action('newsletter/export/csv', method: 'GET', json: false);
    }

    public function test_the_permission_is_enough(): void
    {
        $this->subscribed();
        $this->actingAs([Plugin::PERMISSION_VIEW]);

        $this->assertCount(2, $this->csv($this->action('newsletter/export/csv', method: 'GET', json: false)));
    }

    public function test_the_element_index_exporter_has_the_same_columns(): void
    {
        $this->subscribed('ada@example.com');
        $this->pending('waiting@example.com');

        $rows = (new SubscribersCsv)->export(Subscriber::find()->status(null)->orderBy(['elements.id' => SORT_ASC]));

        $this->assertCount(2, $rows);
        $this->assertSame('ada@example.com', $rows[0]['Email']);
        $this->assertSame('pending', $rows[1]['Status']);
        $this->assertArrayHasKey('Unsubscribe link', $rows[0]);
    }
}
