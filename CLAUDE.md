# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A Craft CMS 5 plugin that exposes four Twig tags and a `SearchLinkField` custom field for talking to any read-only HTTP API that speaks the Elasticsearch response shape (documents as `_source`, search results as `{ hits: { hits: [...], total: { value } }, took }`).

The plugin makes no assumptions about the shape of the documents it returns — it's a transport + UI layer. Anything site-specific (field names, URL rewriting, IIIF proxying, etc.) lives in the consuming project.

## Package info

- Composer name: `cogapp/craft-collections-proxy`
- PHP namespace: `cogapp\collectionsproxy`
- Plugin handle: `collections-proxy`
- Minimum PHP: 8.3
- Craft CMS: ^5.0
- Public repo: https://github.com/CogappLabs/craft-collections-proxy

## What the plugin provides

The public surface is:

1. **`SearchLinkField`** (`src/fields/SearchLinkField.php`) — a custom field type that lets editors search the Collections API and store a link to a collection document (persists `documentId`, `documentTitle`, `documentThumbnail`). Per-field settings for `index`, `thumbnailField`, and `titleField`, each falling back to the plugin's global setting (then `'title'` for titleField). Vanilla JS (no Sprig); posts to `SearchController::actionQuery` via `Craft.sendActionRequest`.
2. **`{% collectionDocument 'index' id as doc %}` Twig tag** — fetches a single document for server-rendered item pages.
3. **`{% collectionDocuments 'index' ids[, fields] as docs %}` Twig tag** — batch-fetches N documents via `_msearch` with an `ids` query, returns an array keyed by ID. Useful on index/listing templates that need lots of thumbnails in one round-trip.
4. **`{% collectionSearch 'index', query[, perPage[, page]] as results %}` Twig tag** — runs a plain `?q=` text search and assigns the normalised `{results, totalResults, took}` shape. Intended for server-rendered fragments (e.g. Datastar SSE responses).
5. **`{% collectionEsSearch 'index', queryOrBody[, params] as results %}` Twig tag** _(experimental)_ — runs a full ES query body via `POST /api/v1/_msearch`; returns `{results, totalResults, took, aggregations}`. The second arg is either a **PHP query-file name** (loaded from `queryPath` via `QueryLoader`) or an **inline Twig hash** used verbatim as the ES body. Runtime dispatches on type. Signature, `queryPath` default, and response shape may change without a deprecation cycle until this stabilises.

Plus the supporting pieces:

- **Plugin settings** (`src/models/Settings.php`) — native Craft `Model` with `EnvAttributeParserBehavior` on `serverApiUrl`, `publicApiUrl`, `index`, and `queryPath`. Visible at **Settings → Plugins → Collections Proxy**. Other fields: `titleField`, `itemFields`.
- **`ApiClient` service** (`src/services/ApiClient.php`) — Guzzle-based HTTP client. Methods: `search`, `esSearch`, `getDocument`, `getDocuments`. Treat as internal — consumers should use the Twig tags rather than `plugin.apiClient.*`.
- **`QueryLoader` service** (`src/services/QueryLoader.php`) — resolves and evaluates `<name>.php` query files under `queryPath`. Each file must `return` a `callable(array $params): array` or an `array`. Path-traversal protection built in. Internal — consumers should use `{% collectionEsSearch %}` rather than `plugin.queryLoader.*`.
- **`SearchController`** (`src/controllers/SearchController.php`) — single action (`actionQuery`) behind the SearchLinkField's search box.

## Architecture

### `Plugin.php`
- Extends `craft\base\Plugin`
- `schemaVersion = '1.0.0'`, `hasCpSettings = true`
- Registers the `apiClient` component via `config()`
- `init()` wires up the Twig extension and registers the `SearchLinkField` type inside `$app->onInit()`
- `createSettingsModel()` returns a `Settings` instance; `settingsHtml()` renders `collections-proxy/_settings`

### Settings model
- `EnvAttributeParserBehavior` on `serverApiUrl`, `publicApiUrl`, `index` — values starting with `$` resolve from env vars at read time
- `defineRules()` marks the three URL / index fields as required strings

### Guzzle client injection
- `ApiClient` accepts an optional `GuzzleHttp\ClientInterface` in its constructor (defaults to a new `Client` instance). Tests pass in a `Client` wired to a `MockHandler` to stub responses without real HTTP.

## Development

### Tests

PHPUnit tests for `ApiClient` live in `tests/unit/ApiClientTest.php`. Uses `GuzzleHttp\Handler\MockHandler` + injected client pattern — no live HTTP, no Craft bootstrap.

Run from the testbed / consuming project so PHP + Craft autoload are available via the DDEV container:

```bash
ddev exec bash -c "cd /var/www/craft-collections-proxy && vendor/bin/phpunit"
```

Or directly via the plugin's `composer test` script inside any PHP 8.3+ environment with the dev deps installed.

### PHPStan

Level 8, no baseline. Union types like `craft\web\View | craft\console\View` and `craft\web\Application | craft\console\Application` are narrowed with docblock `@var` casts rather than `instanceof` checks — see `Plugin.php` and controllers for the pattern.

```bash
ddev exec bash -c "cd /var/www/craft-collections-proxy && composer phpstan"
```

### Local dev against craft-searchkit

The `craft-searchkit` testbed depends on this plugin via a VCS repository entry in its `composer.json`. To test local edits:

1. Check out this repo as a sibling to the consuming Craft project.
2. `.ddev/docker-compose.craft-collections-proxy.yaml` in `craft-searchkit/` bind-mounts this directory into the web container at `/var/www/craft-collections-proxy`.
3. A `post-start` hook in `craft-searchkit/.ddev/config.yaml` symlinks `vendor/cogapp/craft-collections-proxy/` → the bind mount.
4. Edits are picked up immediately; `ddev exec php craft clear-caches/compiled-templates` if Twig changes don't show up.

`composer install` / `composer update` in the testbed will clobber the symlink with the real VCS zipball — re-run `ddev restart` (or the symlink command) to re-apply.

## Install in a consuming project

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/CogappLabs/craft-collections-proxy" }
]
```

```bash
composer require cogapp/craft-collections-proxy
php craft plugin/install collections-proxy
```

## Expected API contract

The plugin assumes the backend speaks:

- `GET /api/v1/:index?q=&perPage=&page=&fields=` → `{ hits: { hits: [{ _id, _source }], total: { value } }, took }`
- `GET /api/v1/:index/:id?fields=` → the `_source` object directly
- `POST /api/v1/:index/_mget` with `{ ids: [...], fields: '...' }` → `{ docs: [{ _id, _source }, ...] }`

Any HTTP service that returns responses in this shape will work.
