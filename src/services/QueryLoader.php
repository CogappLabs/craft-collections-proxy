<?php

namespace cogapp\collectionsproxy\services;

use Craft;
use cogapp\collectionsproxy\Plugin;
use cogapp\collectionsproxy\exceptions\QueryEvaluationException;
use cogapp\collectionsproxy\exceptions\QueryNotFoundException;
use yii\base\Component;

/**
 * Experimental. Loader conventions (file format, signature, path
 * default) may change without a deprecation cycle until this stabilises.
 *
 * Loads a named PHP query file under the configured `queryPath` and
 * evaluates it into an Elasticsearch request body.
 *
 * Only `.php` files are supported. Each file must `return` either:
 *
 *   - `callable(array $params): array` — preferred, for dynamic queries
 *   - `array` — for a static query with no parameterisation
 *
 * Inline ES bodies written directly in a Twig template bypass this loader
 * entirely — the `{% collectionEsSearch %}` tag detects an array literal
 * at runtime and passes it straight to `ApiClient::esSearch()`.
 *
 * `queryPath` is resolved via `Craft::getAlias()` so it can use path
 * aliases (default `@config/queries` — kept outside `templates/` so
 * these PHP files aren't mistaken for Twig). Query names may not
 * contain `..` segments, leading slashes, or leading dots.
 */
class QueryLoader extends Component
{
    /** Path override. When null, resolved from plugin settings (`queryPath`). */
    public ?string $queryPath = null;

    /**
     * Load and evaluate a named PHP query.
     *
     * @param array<string, mixed> $params Passed to the callable form
     * @return array<string, mixed> ES request body
     * @throws QueryNotFoundException
     * @throws QueryEvaluationException
     */
    public function load(string $name, array $params = []): array
    {
        $this->assertSafeName($name);

        $baseDir = $this->resolveBaseDir();
        if ($baseDir === null) {
            throw new QueryNotFoundException("Query path is not configured — set `queryPath` in plugin settings.");
        }

        $path = $baseDir . DIRECTORY_SEPARATOR . $name . '.php';
        if (!is_file($path)) {
            throw new QueryNotFoundException("No query file found for \"{$name}\" at {$path}.");
        }

        $result = require $path;

        if (is_callable($result)) {
            try {
                $built = $result($params);
            } catch (\Throwable $e) {
                throw new QueryEvaluationException(
                    "PHP query \"{$path}\" threw: " . $e->getMessage(),
                    0,
                    $e,
                );
            }
            if (!is_array($built)) {
                throw new QueryEvaluationException("PHP query callable at \"{$path}\" must return an array.");
            }
            return $built;
        }

        if (is_array($result)) {
            return $result;
        }

        throw new QueryEvaluationException(
            "PHP query \"{$path}\" must return an array or a callable returning an array.",
        );
    }

    private function assertSafeName(string $name): void
    {
        if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '.')) {
            throw new QueryNotFoundException("Invalid query name: \"{$name}\".");
        }
    }

    private function resolveBaseDir(): ?string
    {
        $raw = $this->queryPath ?? $this->pluginSetting('queryPath');
        if ($raw === '') {
            return null;
        }

        if (class_exists(Craft::class, false)) {
            $resolved = Craft::getAlias($raw, false);
            if (is_string($resolved) && $resolved !== '') {
                return rtrim($resolved, DIRECTORY_SEPARATOR);
            }
        }

        return rtrim($raw, DIRECTORY_SEPARATOR);
    }

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
}
