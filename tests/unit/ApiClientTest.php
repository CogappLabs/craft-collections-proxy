<?php

namespace cogapp\collectionsproxy\tests\unit;

use cogapp\collectionsproxy\services\ApiClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ApiClient. These tests run entirely standalone — no
 * Craft bootstrap, no DB, no HTTP — by injecting a pre-built Guzzle
 * client with a MockHandler and passing config overrides directly.
 */
class ApiClientTest extends TestCase
{
    /**
     * Build an ApiClient with a mocked Guzzle handler.
     * Returns [ApiClient, array &$history] — $history captures every
     * dispatched request so tests can assert on the URL and query params.
     *
     * @param list<Response|\Exception> $responses
     */
    private function buildClient(array $responses, array &$history = []): ApiClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $guzzle = new GuzzleClient(['handler' => $stack, 'base_uri' => 'https://api.test']);

        $client = new ApiClient();
        $client->baseUri = 'https://api.test';
        $client->setClient($guzzle);
        return $client;
    }

    public function testSearchReturnsNormalisedShape(): void
    {
        $payload = [
            'took' => 7,
            'hits' => [
                'total' => ['value' => 2],
                'hits' => [
                    ['_id' => 'a', '_source' => ['title' => 'Apple']],
                    ['_id' => 'b', '_source' => ['title' => 'Banana']],
                ],
            ],
        ];

        $client = $this->buildClient([new Response(200, [], json_encode($payload))]);
        $result = $client->search('my-index', 'fruit', 5, 1);

        self::assertSame(2, $result['totalResults']);
        self::assertSame(7, $result['took']);
        self::assertCount(2, $result['results']);
        self::assertSame('a', $result['results'][0]['id']);
        self::assertSame('Apple', $result['results'][0]['source']['title']);
        self::assertSame('b', $result['results'][1]['id']);
    }

    public function testSearchPassesQueryParams(): void
    {
        $history = [];
        $client = $this->buildClient(
            [new Response(200, [], '{"hits":{"hits":[],"total":{"value":0}}}')],
            $history,
        );
        $client->search('my-index', 'hello world', 10, 2);

        /** @var Request $req */
        $req = $history[0]['request'];
        self::assertSame('/api/v1/my-index', $req->getUri()->getPath());
        $query = [];
        parse_str($req->getUri()->getQuery(), $query);
        self::assertSame('hello world', $query['q']);
        self::assertSame('10', $query['perPage']);
        self::assertSame('2', $query['page']);
        // search() no longer sends fields= (displayFields was removed as a plugin setting)
        self::assertArrayNotHasKey('fields', $query);
    }

    public function testSearchOmitsEmptyQueryAndFields(): void
    {
        $history = [];
        $client = $this->buildClient(
            [new Response(200, [], '{"hits":{"hits":[],"total":{"value":0}}}')],
            $history,
        );
        $client->search('my-index');

        /** @var Request $req */
        $req = $history[0]['request'];
        $query = [];
        parse_str($req->getUri()->getQuery(), $query);
        self::assertArrayNotHasKey('q', $query);
        self::assertArrayNotHasKey('fields', $query);
        self::assertSame('20', $query['perPage']);
    }

    public function testSearchReturnsEmptyShapeOnTransportError(): void
    {
        $client = $this->buildClient([new \RuntimeException('connection refused')]);
        $result = $client->search('my-index', 'x');

        self::assertSame([], $result['results']);
        self::assertSame(0, $result['totalResults']);
        self::assertSame(0, $result['took']);
    }

    public function testGetDocumentReturnsSource(): void
    {
        $client = $this->buildClient([
            new Response(200, [], '{"title":"Letitia","artist_names":"Joshua Johnson"}'),
        ]);
        $doc = $client->getDocument('my-index', '3');

        self::assertNotNull($doc);
        self::assertSame('Letitia', $doc['title']);
        self::assertSame('Joshua Johnson', $doc['artist_names']);
    }

    public function testGetDocumentPassesFieldsQuery(): void
    {
        $history = [];
        $client = $this->buildClient([new Response(200, [], '{}')], $history);
        $client->itemFields = 'title,description';
        $client->getDocument('my-index', 'abc');

        /** @var Request $req */
        $req = $history[0]['request'];
        self::assertSame('/api/v1/my-index/abc', $req->getUri()->getPath());
        $query = [];
        parse_str($req->getUri()->getQuery(), $query);
        self::assertSame('title,description', $query['fields']);
    }

    public function testGetDocumentReturnsNullOn404(): void
    {
        $req = new Request('GET', '/api/v1/x/missing');
        $res = new Response(404);
        $client = $this->buildClient([new ClientException('Not Found', $req, $res)]);

        $doc = $client->getDocument('x', 'missing');
        self::assertNull($doc);
    }

    public function testGetDocumentReturnsNullOnTransportError(): void
    {
        $client = $this->buildClient([new \RuntimeException('boom')]);
        self::assertNull($client->getDocument('x', 'abc'));
    }

    public function testGetDocumentsKeysByIdAndPassesFields(): void
    {
        $history = [];
        $payload = [
            'responses' => [
                [
                    'hits' => [
                        'total' => ['value' => 2],
                        'hits' => [
                            ['_id' => 'a', '_source' => ['title' => 'Apple']],
                            ['_id' => 'b', '_source' => ['title' => 'Banana']],
                        ],
                    ],
                ],
            ],
        ];
        $client = $this->buildClient([new Response(200, [], json_encode($payload))], $history);
        $result = $client->getDocuments('my-index', ['a', 'b'], 'title,iiif_thumbnail_url');

        self::assertSame('Apple', $result['a']['title']);
        self::assertSame('Banana', $result['b']['title']);

        /** @var Request $req */
        $req = $history[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertSame('/api/v1/_msearch', $req->getUri()->getPath());
        self::assertSame('application/x-ndjson', $req->getHeaderLine('Content-Type'));

        $raw = $req->getBody()->getContents();
        $lines = array_values(array_filter(explode("\n", $raw), static fn ($l) => $l !== ''));
        self::assertCount(2, $lines);

        $header = json_decode($lines[0], true);
        self::assertSame('my-index', $header['index']);

        $queryBody = json_decode($lines[1], true);
        self::assertSame(['a', 'b'], $queryBody['query']['ids']['values']);
        self::assertSame(2, $queryBody['size']);
        self::assertSame(['title', 'iiif_thumbnail_url'], $queryBody['_source']);
    }

    public function testGetDocumentsOmitsSourceWhenNoFieldsGiven(): void
    {
        $history = [];
        $client = $this->buildClient(
            [new Response(200, [], '{"responses":[{"hits":{"hits":[]}}]}')],
            $history,
        );
        $client->getDocuments('my-index', ['a']);

        /** @var Request $req */
        $req = $history[0]['request'];
        $lines = array_values(array_filter(explode("\n", $req->getBody()->getContents()), static fn ($l) => $l !== ''));
        $queryBody = json_decode($lines[1], true);
        self::assertArrayNotHasKey('_source', $queryBody);
    }

    public function testGetDocumentsReturnsEmptyOnNoIds(): void
    {
        $client = $this->buildClient([]);
        self::assertSame([], $client->getDocuments('my-index', []));
    }

    public function testGetDocumentsReturnsEmptyOnTransportError(): void
    {
        $client = $this->buildClient([new \RuntimeException('boom')]);
        self::assertSame([], $client->getDocuments('my-index', ['a']));
    }

    public function testClientReturnsNullWhenNoBaseUriConfigured(): void
    {
        // No baseUri set, no injected client, no Craft bootstrap — should
        // degrade gracefully rather than crash.
        $client = new ApiClient();
        self::assertNull($client->getDocument('x', 'y'));
        self::assertSame(
            ['results' => [], 'totalResults' => 0, 'took' => 0],
            $client->search('x'),
        );
    }

    public function testEsSearchPostsNdjsonAndReturnsNormalisedShape(): void
    {
        $history = [];
        $payload = [
            'responses' => [
                [
                    'took' => 9,
                    'hits' => [
                        'total' => ['value' => 2],
                        'max_score' => 1.8,
                        'hits' => [
                            ['_id' => 'a', '_score' => 1.8, '_source' => ['title' => 'Apple']],
                            ['_id' => 'b', '_score' => 1.1, '_source' => ['title' => 'Banana']],
                        ],
                    ],
                    'aggregations' => [
                        'medium' => ['buckets' => [['key' => 'oil', 'doc_count' => 12]]],
                    ],
                ],
            ],
        ];
        $client = $this->buildClient([new Response(200, [], json_encode($payload))], $history);

        $body = [
            'query' => ['match' => ['title' => 'fruit']],
            'aggs' => ['medium' => ['terms' => ['field' => 'medium.keyword']]],
            'size' => 24,
        ];
        $result = $client->esSearch('my-index', $body);

        self::assertSame(2, $result['totalResults']);
        self::assertSame(9, $result['took']);
        self::assertCount(2, $result['results']);
        self::assertSame('a', $result['results'][0]['id']);
        self::assertSame('Apple', $result['results'][0]['source']['title']);
        self::assertSame(1.8, $result['results'][0]['score']);
        self::assertSame(12, $result['aggregations']['medium']['buckets'][0]['doc_count']);

        /** @var Request $req */
        $req = $history[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertSame('/api/v1/_msearch', $req->getUri()->getPath());
        self::assertSame('application/x-ndjson', $req->getHeaderLine('Content-Type'));

        $lines = array_values(array_filter(explode("\n", $req->getBody()->getContents()), static fn ($l) => $l !== ''));
        self::assertCount(2, $lines);
        self::assertSame(['index' => 'my-index'], json_decode($lines[0], true));
        self::assertSame($body, json_decode($lines[1], true));
    }

    public function testEsSearchReturnsEmptyShapeOnEsError(): void
    {
        $payload = ['responses' => [['error' => ['type' => 'parsing_exception', 'reason' => 'bad']]]];
        $client = $this->buildClient([new Response(200, [], json_encode($payload))]);

        $result = $client->esSearch('my-index', ['query' => ['match_all' => (object) []]]);
        self::assertSame([], $result['results']);
        self::assertSame(0, $result['totalResults']);
        self::assertSame([], $result['aggregations']);
    }

    public function testEsSearchReturnsEmptyShapeOnTransportError(): void
    {
        $client = $this->buildClient([new \RuntimeException('boom')]);
        $result = $client->esSearch('my-index', ['query' => ['match_all' => (object) []]]);
        self::assertSame([], $result['results']);
        self::assertSame(0, $result['totalResults']);
        self::assertSame(0, $result['took']);
        self::assertSame([], $result['aggregations']);
    }

    public function testEsSearchHandlesMissingScoreGracefully(): void
    {
        $payload = [
            'responses' => [[
                'took' => 1,
                'hits' => [
                    'total' => ['value' => 1],
                    'hits' => [['_id' => 'a', '_source' => ['title' => 'No score']]],
                ],
            ]],
        ];
        $client = $this->buildClient([new Response(200, [], json_encode($payload))]);
        $result = $client->esSearch('my-index', []);
        self::assertNull($result['results'][0]['score']);
    }
}
