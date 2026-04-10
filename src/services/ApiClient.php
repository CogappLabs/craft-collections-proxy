<?php

namespace cogapp\collectionsproxy\services;

use cogapp\collectionsproxy\Plugin;
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
 *
 * Test seams: callers can override `$baseUri`, `$itemFields`,
 * `$displayFields`, or inject a pre-configured `GuzzleClient` via
 * `setClient()` to run without a full Craft bootstrap.
 */
class ApiClient extends Component
{
    /** Base URI override. Falls back to the plugin settings when null. */
    public ?string $baseUri = null;

    /** Fields query override for getDocument(). Falls back to plugin settings when null. */
    public ?string $itemFields = null;

    /** Whether Guzzle verifies SSL. Default true; false in dev-mode fallback. */
    public ?bool $verify = null;

    private ?GuzzleClient $client = null;

    /** Test seam: inject a pre-built Guzzle client (with mocked handler, etc.). */
    public function setClient(GuzzleClient $client): void
    {
        $this->client = $client;
    }

    private function client(): ?GuzzleClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $baseUri = $this->resolveBaseUri();
        if (!$baseUri) {
            return null;
        }

        $this->client = new GuzzleClient([
            'base_uri' => $baseUri,
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify' => $this->resolveVerify(),
            'headers' => [
                'User-Agent' => 'craft-collections-proxy/1.0',
                'Accept' => 'application/json',
            ],
        ]);

        return $this->client;
    }

    protected function resolveBaseUri(): ?string
    {
        if ($this->baseUri !== null) {
            return $this->baseUri;
        }
        $raw = $this->pluginSetting('serverApiUrl');
        if ($raw === '') {
            return null;
        }
        if (!class_exists(\craft\helpers\App::class, false)) {
            return $raw;
        }
        $parsed = \craft\helpers\App::parseEnv($raw);
        return is_string($parsed) ? $parsed : null;
    }

    protected function resolveVerify(): bool
    {
        if ($this->verify !== null) {
            return $this->verify;
        }
        if (class_exists(\craft\helpers\App::class, false) && \craft\helpers\App::env('CRAFT_ENVIRONMENT') === 'dev') {
            return false;
        }
        return true;
    }

    protected function resolveItemFields(): string
    {
        return $this->itemFields ?? $this->pluginSetting('itemFields');
    }

    /**
     * Read a string property off the plugin's Settings model, returning
     * $default when running outside a full Craft bootstrap (e.g. unit tests
     * where Plugin::class isn't autoloaded).
     */
    private function pluginSetting(string $key, string $default = ''): string
    {
        if (!class_exists(Plugin::class, false)) {
            return $default;
        }
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return $default;
        }
        $value = $plugin->getSettings()->{$key} ?? $default;
        return is_string($value) ? $value : $default;
    }

    protected function logError(string $message): void
    {
        if (class_exists(\Craft::class, false)) {
            \Craft::error($message, __METHOD__);
        }
    }

    /**
     * Fetch a single document by ID.
     * Returns the _source array, or null if the document is missing
     * or the request fails.
     *
     * @return array<string, mixed>|null
     */
    public function getDocument(string $index, string $id): ?array
    {
        $client = $this->client();
        if ($client === null) {
            return null;
        }

        $queryParams = [];
        $fields = $this->resolveItemFields();
        if ($fields !== '') {
            $queryParams['fields'] = $fields;
        }

        try {
            $response = $client->get("/api/{$index}/{$id}", ['query' => $queryParams]);
            $source = json_decode($response->getBody()->getContents(), true);
            return is_array($source) ? $source : null;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }
            $this->logError('Collections API getDocument error: ' . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            $this->logError('Collections API getDocument error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Search an index. Returns a normalised shape:
     *   ['results' => [...], 'totalResults' => int, 'took' => int]
     *
     * Used by the plugin's CP search panel and any custom PHP callers.
     * The React/Searchkit frontend does NOT use this — it talks to the
     * API directly and defines its own result projection client-side.
     *
     * @return array{results: array<int, array{id: mixed, source: array<string, mixed>}>, totalResults: int, took: int}
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

        try {
            $response = $client->get("/api/{$index}", ['query' => $queryParams]);
            $body = json_decode($response->getBody()->getContents(), true) ?: [];
        } catch (\Exception $e) {
            $this->logError('Collections API search error: ' . $e->getMessage());
            return ['results' => [], 'totalResults' => 0, 'took' => 0];
        }

        return self::parseSearchResponse($body);
    }

    /**
     * Pure parser for an OpenSearch-shaped search response. Extracted so
     * it can be unit tested without any HTTP or Craft dependencies.
     *
     * @param array<string, mixed> $body Decoded response body
     * @return array{results: array<int, array{id: mixed, source: array<string, mixed>}>, totalResults: int, took: int}
     */
    public static function parseSearchResponse(array $body): array
    {
        $hitsEnvelope = is_array($body['hits'] ?? null) ? $body['hits'] : [];
        $rawHits = $hitsEnvelope['hits'] ?? [];
        $hits = is_array($rawHits) ? $rawHits : [];

        $results = array_map(
            fn(array $hit) => ['id' => $hit['_id'] ?? null, 'source' => $hit['_source'] ?? []],
            $hits,
        );

        return [
            'results' => $results,
            'totalResults' => (int) ($body['hits']['total']['value'] ?? 0),
            'took' => (int) ($body['took'] ?? 0),
        ];
    }
}
