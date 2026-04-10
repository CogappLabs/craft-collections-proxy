<?php

namespace cogapp\collectionsproxy\tests\unit;

use cogapp\collectionsproxy\services\ApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the static ApiClient::parseSearchResponse() helper.
 * Pure function — no HTTP, no Craft, no filesystem.
 */
class ParseSearchResponseTest extends TestCase
{
    public function testParsesFullResponse(): void
    {
        $body = [
            'took' => 12,
            'hits' => [
                'total' => ['value' => 3],
                'hits' => [
                    ['_id' => '1', '_source' => ['title' => 'One']],
                    ['_id' => '2', '_source' => ['title' => 'Two']],
                    ['_id' => '3', '_source' => ['title' => 'Three']],
                ],
            ],
        ];

        $result = ApiClient::parseSearchResponse($body);

        self::assertSame(12, $result['took']);
        self::assertSame(3, $result['totalResults']);
        self::assertCount(3, $result['results']);
        self::assertSame('1', $result['results'][0]['id']);
        self::assertSame('One', $result['results'][0]['source']['title']);
    }

    public function testEmptyResponse(): void
    {
        $result = ApiClient::parseSearchResponse([]);
        self::assertSame(['results' => [], 'totalResults' => 0, 'took' => 0], $result);
    }

    public function testEmptyHits(): void
    {
        $body = ['took' => 5, 'hits' => ['total' => ['value' => 0], 'hits' => []]];
        $result = ApiClient::parseSearchResponse($body);

        self::assertSame([], $result['results']);
        self::assertSame(0, $result['totalResults']);
        self::assertSame(5, $result['took']);
    }

    public function testHandlesMissingSource(): void
    {
        $body = ['hits' => ['hits' => [['_id' => 'x']]]];
        $result = ApiClient::parseSearchResponse($body);

        self::assertSame('x', $result['results'][0]['id']);
        self::assertSame([], $result['results'][0]['source']);
    }

    public function testCoercesTotalAndTookToInts(): void
    {
        $body = ['took' => '42', 'hits' => ['total' => ['value' => '100'], 'hits' => []]];
        $result = ApiClient::parseSearchResponse($body);

        self::assertSame(42, $result['took']);
        self::assertSame(100, $result['totalResults']);
    }
}
