<?php

namespace cogapp\collectionsproxy\tests\unit;

use cogapp\collectionsproxy\exceptions\QueryEvaluationException;
use cogapp\collectionsproxy\exceptions\QueryNotFoundException;
use cogapp\collectionsproxy\services\QueryLoader;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QueryLoader. Runs standalone — no Craft bootstrap, no
 * HTTP — by setting `queryPath` directly on the loader instance.
 */
class QueryLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/collections-proxy-loader-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        @rmdir($this->dir);
    }

    private function loader(): QueryLoader
    {
        $loader = new QueryLoader();
        $loader->queryPath = $this->dir;
        return $loader;
    }

    public function testLoadsPhpCallable(): void
    {
        file_put_contents(
            $this->dir . '/objects.php',
            "<?php return static function(array \$params): array { return ['query' => ['match' => ['title' => \$params['q'] ?? '']]]; };",
        );

        $body = $this->loader()->load('objects', ['q' => 'fruit']);
        self::assertSame(['query' => ['match' => ['title' => 'fruit']]], $body);
    }

    public function testLoadsPhpArray(): void
    {
        file_put_contents(
            $this->dir . '/static.php',
            "<?php return ['query' => ['match_all' => new \\stdClass()]];",
        );

        $body = $this->loader()->load('static', []);
        self::assertArrayHasKey('match_all', $body['query']);
    }

    public function testMissingQueryThrows(): void
    {
        $this->expectException(QueryNotFoundException::class);
        $this->loader()->load('does-not-exist', []);
    }

    public function testPhpReturningWrongTypeThrows(): void
    {
        file_put_contents($this->dir . '/bad.php', "<?php return 'not an array';");
        $this->expectException(QueryEvaluationException::class);
        $this->loader()->load('bad', []);
    }

    public function testPhpCallableReturningWrongTypeThrows(): void
    {
        file_put_contents(
            $this->dir . '/bad.php',
            "<?php return static function(array \$p) { return 'nope'; };",
        );
        $this->expectException(QueryEvaluationException::class);
        $this->loader()->load('bad', []);
    }

    public function testPhpCallableThrowingIsWrapped(): void
    {
        file_put_contents(
            $this->dir . '/throws.php',
            "<?php return static function(array \$p) { throw new \\RuntimeException('boom'); };",
        );
        $this->expectException(QueryEvaluationException::class);
        $this->loader()->load('throws', []);
    }

    public function testPathTraversalRejected(): void
    {
        $this->expectException(QueryNotFoundException::class);
        $this->loader()->load('../etc/passwd', []);
    }

    public function testLeadingSlashRejected(): void
    {
        $this->expectException(QueryNotFoundException::class);
        $this->loader()->load('/objects', []);
    }

    public function testLeadingDotRejected(): void
    {
        $this->expectException(QueryNotFoundException::class);
        $this->loader()->load('.hidden', []);
    }

    public function testEmptyNameRejected(): void
    {
        $this->expectException(QueryNotFoundException::class);
        $this->loader()->load('', []);
    }
}
