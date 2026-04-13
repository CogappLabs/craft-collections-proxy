<?php

namespace cogapp\collectionsproxy\controllers;

use Craft;
use cogapp\collectionsproxy\Plugin;
use craft\web\Controller;
use yii\web\Response;

/**
 * CP search controller. Backs the Collections Proxy -> Search panel with a
 * JSON action endpoint that wraps ApiClient::search().
 *
 * Reached via the standard Craft action URL:
 *   actions/collections-proxy/search/query?index=...&q=...&perPage=...
 */
class SearchController extends Controller
{
    /**
     * JSON search endpoint for the CP search page.
     */
    public function actionQuery(): Response
    {
        $this->requirePermission('accessCp');

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $index = (string) $request->getQueryParam('index', '');
        $query = (string) $request->getQueryParam('q', '');
        $perPage = (int) $request->getQueryParam('perPage', 20);
        $page = (int) $request->getQueryParam('page', 1);

        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return $this->asJson(['error' => 'Plugin not available.']);
        }

        if ($index !== '' && !preg_match('/^[a-zA-Z0-9._-]+$/', $index)) {
            $this->response->setStatusCode(400);
            return $this->asJson(['error' => 'Invalid index name']);
        }

        if ($index === '') {
            $index = $plugin->getSettings()->index;
        }

        if ($index === '') {
            return $this->asJson([
                'error' => 'No index configured or supplied.',
                'results' => [],
                'totalResults' => 0,
                'took' => 0,
            ]);
        }

        $result = $plugin->apiClient->search($index, $query, $perPage, $page);

        return $this->asJson($result);
    }

    /**
     * Fetch a single document by index and ID. Returns the _source directly.
     */
    public function actionGetDocument(): Response
    {
        $this->requirePermission('accessCp');

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $index = (string) $request->getQueryParam('index', '');
        $id = (string) $request->getQueryParam('id', '');

        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return $this->asJson(['error' => 'Plugin not available.']);
        }

        if ($index === '') {
            $index = $plugin->getSettings()->index;
        }

        if ($index === '' || $id === '') {
            return $this->asJson(null);
        }

        $doc = $plugin->apiClient->getDocument($index, $id);

        return $this->asJson($doc);
    }
}
