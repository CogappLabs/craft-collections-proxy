<?php

namespace cogapp\collectionsproxy\services;

use cogapp\collectionsproxy\Plugin;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use yii\base\Component;

/**
 * Thin HTTP client for a read-only Collections API that speaks the
 * Elasticsearch response shape.
 *
 * Endpoints consumed:
 *
 *   GET  /api/v1/:index?q=&perPage=&page=&fields=
 *     → { hits: { hits: [{ _id, _source }], total: { value } }, took }
 *
 *   GET  /api/v1/:index/:id?fields=
 *     → the _source object directly
 *
 *   POST /api/v1/_msearch            NDJSON: { "index": ":index" } / query
 *     → { responses: [{ hits: { hits: [{ _id, _source }], total: { value } }, took }, ...] }
 *       (used by `getDocuments()` with an `ids` query)
 *
 * This class powers the three Twig tags (`{% collectionDocument %}`,
 * `{% collectionDocuments %}`, `{% collectionSearch %}`) and the
 * `SearchLinkField`'s search-box action. It is considered internal —
 * consuming templates should use the tags, not reach into
 * `Plugin::getInstance()->apiClient` directly.
 *
 * Test seams: callers can override `$baseUri` or `$itemFields`, or inject
 * a pre-configured `GuzzleClient` via `setClient()` to run without a full
 * Craft bootstrap.
 */
class ApiClient extends Component
{
    /** Base URI override. When null, resolved from the plugin settings (`serverApiUrl`). */
    public ?string $baseUri = null;

    /**
     * `fields=` query override for document fetches (`getDocument` / `getDocuments`).
     * When null, falls back to the plugin's `itemFields` setting; when that is
     * empty too, no `fields=` parameter is sent and the backend returns the full
     * `_source`.
     */
    public ?string $itemFields = null;

    private ?GuzzleClient $client = null;

    /**
     * Test seam: inject a pre-built Guzzle client (typically wired to a
     * `MockHandler`) so tests can exercise the full request pipeline without
     * needing network access or a Craft bootstrap.
     */
    public function setClient(GuzzleClient $client): void
    {
        $this->client = $client;
    }

    /**
     * Lazy-constructs the Guzzle client on first use. Returns null when no
     * base URI is configured so every public call can no-op gracefully.
     */
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
            'verify' => !self::isLocalDevHost($baseUri),
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
        return $raw !== '' ? $raw : null;
    }

    /**
     * Skip TLS verification only when the target host is a conventional
     * local-dev endpoint (wrangler dev, DDEV host bridge, etc.) where
     * mkcert / self-signed certs are the norm. Real hostnames stay strict.
     */
    private static function isLocalDevHost(string $baseUri): bool
    {
        $host = parse_url($baseUri, PHP_URL_HOST);
        return in_array($host, ['localhost', '127.0.0.1', 'host.docker.internal'], true);
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
            $response = $client->get("/api/v1/{$index}/{$id}", ['query' => $queryParams]);
            $source = json_decode($response->getBody()->getContents(), true);
            return is_array($source) ? $source : null;
        } catch (ClientException $e) {
            $status = $e->getResponse()->getStatusCode();
            if ($status === 404) {
                return null;
            }
            $this->logError("Collections API getDocument error (HTTP {$status}): " . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            $this->logError('Collections API getDocument error (code ' . $e->getCode() . '): ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch multiple documents by ID in a single request.
     * Returns an array keyed by document ID => _source.
     *
     * Implemented via `_msearch` + an `ids` query rather than `_mget`, so it
     * works against any backend that exposes `POST /api/v1/_msearch` (the
     * standard multi-search endpoint). The request body is NDJSON with a
     * `size` equal to the number of IDs so Elasticsearch's default 10-hit
     * cap doesn't silently truncate large lookups, and optional `_source`
     * filtering mirrors what `getDocument()` does with its `fields=` query
     * param.
     *
     * @param string[] $ids
     * @param string|null $fields Comma-separated field list (null = use itemFields setting)
     * @return array<string, array<string, mixed>>
     */
    public function getDocuments(string $index, array $ids, ?string $fields = null): array
    {
        $client = $this->client();
        if ($client === null || $ids === []) {
            return [];
        }

        $resolvedFields = $fields ?? $this->resolveItemFields();

        $queryBody = [
            'query' => ['ids' => ['values' => array_values($ids)]],
            'size' => count($ids),
        ];
        if ($resolvedFields !== '') {
            $queryBody['_source'] = array_map('trim', explode(',', $resolvedFields));
        }

        $ndjson = json_encode(['index' => $index]) . "\n"
            . json_encode($queryBody) . "\n";

        try {
            $response = $client->post('/api/v1/_msearch', [
                'body' => $ndjson,
                'headers' => ['Content-Type' => 'application/x-ndjson'],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            $first = is_array($data) && is_array($data['responses'] ?? null)
                ? ($data['responses'][0] ?? null)
                : null;
            if (!is_array($first)) {
                return [];
            }
            $hits = $first['hits']['hits'] ?? [];
            if (!is_array($hits)) {
                return [];
            }

            $result = [];
            foreach ($hits as $hit) {
                if (isset($hit['_id'], $hit['_source']) && is_array($hit['_source'])) {
                    $result[(string) $hit['_id']] = $hit['_source'];
                }
            }
            return $result;
        } catch (\Exception $e) {
            $this->logError('Collections API getDocuments error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Search an index. Returns a normalised shape:
     *   ['results' => [...], 'totalResults' => int, 'took' => int]
     *
     * Called by the `{% collectionSearch %}` Twig tag and by the
     * `SearchLinkField`'s AJAX search box (via SearchController).
     * Returns a safe empty shape on transport / HTTP errors so callers
     * never have to null-check.
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
            $response = $client->get("/api/v1/{$index}", ['query' => $queryParams]);
            $body = json_decode($response->getBody()->getContents(), true) ?: [];
        } catch (ClientException $e) {
            $status = $e->getResponse()->getStatusCode();
            $this->logError("Collections API search error (HTTP {$status}): " . $e->getMessage());
            return ['results' => [], 'totalResults' => 0, 'took' => 0];
        } catch (\Exception $e) {
            $this->logError('Collections API search error (code ' . $e->getCode() . '): ' . $e->getMessage());
            return ['results' => [], 'totalResults' => 0, 'took' => 0];
        }

        return self::parseSearchResponse($body);
    }

    /**
     * Pure parser for an Elasticsearch-shaped search response. Extracted so
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
