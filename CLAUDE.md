# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A Craft CMS 5 plugin that exposes a lightweight HTTP client and Twig tag for a read-only Collections API speaking the Elasticsearch / OpenSearch response shape. Extracted from the Craft module that originally lived inside [`craft-searchkit`](https://github.com/CogappLabs/craft-searchkit).

Designed to sit in front of any of the FAMSF Collections API flavours:

- **cf-collections-api** — Hono on Cloudflare Workers (primary, recommended)
- **bun-collections-api** — Bun/Elysia on Railway
- **laravel-collections-api** — Laravel Octane + FrankenPHP on Railway

All three speak identical endpoints, so the backend can be swapped by changing the `serverApiUrl` / `publicApiUrl` settings.

## Package info

- Composer name: `cogapp/craft-collections-proxy`
- PHP namespace: `cogapp\collectionsproxy`
- Plugin handle: `collections-proxy`
- Minimum PHP: 8.3
- Craft CMS: ^5.0
- Public repo: https://github.com/CogappLabs/craft-collections-proxy

## What the plugin provides

- **Plugin settings** (`src/models/Settings.php`) — native Craft `Model` with `EnvAttributeParserBehavior` on the `serverApiUrl`, `publicApiUrl`, and `index` attributes. Visible at **Settings → Plugins → Collections Proxy**. Other fields: `titleField`, `itemFields`. (`searchFields` and `displayFields` were removed — they were Searchkit/frontend concerns that bled through. Hardcode search + result attributes in your consuming site's React code.)
- **`ApiClient` service** (`src/services/ApiClient.php`) — Guzzle-based HTTP client with injectable client for tests. Methods: `search($index, $query, $perPage, $page)` returns `['results' => [...], 'totalResults' => int, 'took' => int]`; `getDocument($index, $id)` returns the `_source` document directly. Shared `pluginSetting(string $key, string $default)` helper consolidates the "read from plugin settings with test-context fallback" pattern so `resolveItemFields` and `resolveBaseUri` stay one-liners.
- **`{% collectionDocument %}` Twig tag** (`src/web/twig/`) — parses to `{% collectionDocument 'index' id as doc %}`, calls `ApiClient::getDocument()` via a custom node, and assigns the result to the given variable name. Registered via `src/web/twig/Extension.php`.
- **CP nav item + subnav** — registers a "Collections Proxy" nav entry with two sub-pages: **Search** (a vanilla-JS test panel at `/admin/collections-proxy` that calls `SearchController::actionQuery` via `Craft.sendActionRequest`) and **Settings** (deep link into the standard plugin settings page). Template at `src/templates/search.twig`, asset bundle at `src/web/assets/SearchAsset.php`.
- **`SearchController`** (`src/controllers/SearchController.php`) — AJAX endpoint behind the CP panel; reuses the `ApiClient` service.

## Architecture

### `Plugin.php`
- Extends `craft\base\Plugin`
- `schemaVersion = '1.0.0'`, `hasCpSettings = true`
- Registers the `apiClient` component via `config()`
- `init()` wires up the Twig extension, CP URL rules, and CP nav item inside `$app->onInit()`
- `createSettingsModel()` returns a `Settings` instance; `settingsHtml()` renders `collections-proxy/_settings`

### Settings model
- `EnvAttributeParserBehavior` on `serverApiUrl`, `publicApiUrl`, `index` — values starting with `$` resolve from env vars at read time
- `defineRules()` marks the three URL / index fields as required strings

### Guzzle client injection
- `ApiClient` accepts an optional `GuzzleHttp\ClientInterface` in its constructor (defaults to a new `Client` instance). Tests pass in a `Client` wired to a `MockHandler` to stub responses without real HTTP.

## Development

### Tests

14 PHPUnit tests, 39 assertions. Uses `GuzzleHttp\Handler\MockHandler` + injected client pattern — no live HTTP in the test suite.

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

1. Check out this repo as a sibling to `craft-searchkit/` (both under `~/git/famsf-collections/`).
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

- `GET /api/:index?q=&perPage=&page=&fields=` → `{ hits: { hits: [{ _id, _source }], total: { value } }, took }`
- `GET /api/:index/:id?fields=` → the `_source` object directly

All three sibling Collections API flavours conform to this shape.

## Related repositories

- **craft-searchkit** — [github.com/CogappLabs/craft-searchkit](https://github.com/CogappLabs/craft-searchkit) — the Craft CMS site that consumes this plugin
- **cf-collections-api** — [github.com/CogappLabs/cf-collections-api](https://github.com/CogappLabs/cf-collections-api) — Hono/Workers backend (primary)
- **bun-collections-api** — [github.com/CogappLabs/bun-collections-api](https://github.com/CogappLabs/bun-collections-api)
- **laravel-collections-api** — [github.com/CogappLabs/laravel-collections-api](https://github.com/CogappLabs/laravel-collections-api)
