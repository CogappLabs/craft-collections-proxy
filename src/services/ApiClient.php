<?php

namespace cogapp\collectionsproxy\services;

use Craft;
use cogapp\collectionsproxy\Plugin;
use craft\helpers\App;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use yii\base\Component;

/**
 * Thin HTTP client for a read-only Collections API that speaks the
 * Elasticsearch/OpenSearch response shape:
 *
 *   GET /api/:index?q=&perPage=&page=&fields=
 *     → { hits: { hits: [{ _id, _source }], total: { value } }, took }
 *
 *   GET /api/:index/:id?fields=
 *     → the _source object directly
 *
 * Used by the Twig `{% collectionDocument %}` tag and available for
 * programmatic use via Plugin::getInstance()->apiClient.
 */
class ApiClient extends Component
{
    private ?GuzzleClient $client = null;

    private function client(): ?GuzzleClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $settings = Plugin::getInstance()->getSettings();
        $baseUri = App::parseEnv($settings->serverApiUrl);
        if (!$baseUri) {
            return null;
        }

        $isDev = App::env('CRAFT_ENVIRONMENT') === 'dev';

        $this->client = new GuzzleClient([
            'base_uri' => $baseUri,
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify' => !$isDev,
            'headers' => [
                'User-Agent' => 'craft-collections-proxy/1.0',
                'Accept' => 'application/json',
            ],
        ]);

        return $this->client;
    }

    /**
     * Fetch a single document by ID.
     * Returns the _source array, or null if the document is missing
     * or the request fails.
     */
    public function getDocument(string $index, string $id): ?array
    {
        $client = $this->client();
        if ($client === null) {
            return null;
        }

        $queryParams = [];
        $itemFields = Plugin::getInstance()->getSettings()->itemFields;
        if ($itemFields !== '') {
            $queryParams['fields'] = $itemFields;
        }

        try {
            $response = $client->get("/api/{$index}/{$id}", ['query' => $queryParams]);
            $source = json_decode($response->getBody()->getContents(), true);
            return is_array($source) ? $source : null;
        } catch (ClientException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
                return null;
            }
            Craft::error('Collections API getDocument error: ' . $e->getMessage(), __METHOD__);
            return null;
        } catch (\Exception $e) {
            Craft::error('Collections API getDocument error: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Search an index. Returns a normalised shape:
     *   ['results' => [...], 'totalResults' => int, 'took' => int]
     *
     * Available as an escape hatch for server-side search. The React
     * frontend calls the API directly from the browser — this is only
     * for unusual cases (admin tooling, exports, etc.).
     */
    public function search(string $index, string $query = '', int $perPage = 20, int $page = 1): array
    {
        $client = $this->client();
        if ($client === null) {
            return ['results' => [], 'totalResults' => 0, 'took' => 0];
        }

        $queryParams = ['perPage' => $perPage, 'page' => $page];
        if ($query !== '') {
            $queryParams['q'] = $query;
        }

        $displayFields = Plugin::getInstance()->getSettings()->displayFields;
        if ($displayFields !== '') {
            $queryParams['fields'] = $displayFields;
        }

        try {
            $response = $client->get("/api/{$index}", ['query' => $queryParams]);
            $body = json_decode($response->getBody()->getContents(), true) ?: [];
        } catch (\Exception $e) {
            Craft::error('Collections API search error: ' . $e->getMessage(), __METHOD__);
            return ['results' => [], 'totalResults' => 0, 'took' => 0];
        }

        $hits = $body['hits']['hits'] ?? [];
        $results = array_map(
            fn(array $hit) => ['id' => $hit['_id'] ?? null, 'source' => $hit['_source'] ?? []],
            $hits,
        );

        return [
            'results' => $results,
            'totalResults' => $body['hits']['total']['value'] ?? 0,
            'took' => $body['took'] ?? 0,
        ];
    }
}
