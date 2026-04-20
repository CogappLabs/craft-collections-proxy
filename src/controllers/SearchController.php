<?php

namespace cogapp\collectionsproxy\controllers;

use Craft;
use cogapp\collectionsproxy\Plugin;
use craft\web\Controller;
use yii\web\Response;

/**
 * AJAX backend for the `SearchLinkField` search box.
 *
 * The field's vanilla-JS widget calls this action via
 * `Craft.sendActionRequest`, which requires an authenticated CP user —
 * `requirePermission('accessCp')` below is the trust boundary.
 *
 * Reached via the standard Craft action URL:
 *   actions/collections-proxy/search/query?index=…&q=…&perPage=…&page=…
 */
class SearchController extends Controller
{
    /**
     * JSON search endpoint. Validates the `index` param against a strict
     * character allow-list (prevents abuse of the action to probe arbitrary
     * backend paths), falls back to the plugin's default index when omitted,
     * and delegates to `ApiClient::search()`.
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
}
