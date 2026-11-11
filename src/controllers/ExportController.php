<?php

namespace nineteenninetyfour\newsletter\controllers;

use craft\web\Controller;
use nineteenninetyfour\newsletter\elements\Subscriber;
use nineteenninetyfour\newsletter\Plugin;
use yii\web\Response;

/**
 * The Download CSV button on the Newsletter page.
 */
class ExportController extends Controller
{
    /**
     * newsletter/export: confirmed subscribers by default; ?status=all,
     * pending or unsubscribed for the others.
     */
    public function actionCsv(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        $status = (string) $this->request->getQueryParam('status', Subscriber::STATUS_SUBSCRIBED);
        $statuses = array_keys(Subscriber::statuses());
        $status = $status === 'all' || in_array($status, $statuses, true) ? $status : Subscriber::STATUS_SUBSCRIBED;

        $query = Subscriber::find()->status($status === 'all' ? null : $status)->orderBy(['elements.dateCreated' => SORT_ASC, 'elements.id' => SORT_ASC]);
        $service = Plugin::getInstance()->subscribers;

        $out = fopen('php://temp', 'w+');
        // A byte-order mark, so Excel reads names with accents properly.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $service->csvHeadings(), escape: '');

        foreach ($service->csvRows($query) as $row) {
            fputcsv($out, $row, escape: '');
        }

        rewind($out);
        $filename = 'newsletter-'.($status === 'all' ? 'all' : $status).'-'.date('Y-m-d').'.csv';

        $this->response->getHeaders()->set('Cache-Control', 'no-store, private');

        return $this->response->sendStreamAsFile($out, $filename, ['mimeType' => 'text/csv; charset=UTF-8']);
    }
}
