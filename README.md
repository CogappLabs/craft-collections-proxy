# Craft Collections Proxy

A Craft CMS 5 plugin that exposes a lightweight HTTP client and Twig tag for a read-only Collections API that speaks the Elasticsearch / OpenSearch response shape.

Designed to sit in front of any of the [FAMSF Collections API](https://github.com/CogappLabs) flavours (Bun/Elysia on Railway, Laravel Octane on Railway, or Hono on Cloudflare Workers). All three speak the same endpoints, so you can swap between them by changing the `serverApiUrl` setting.

## What it gives you

- **`{% collectionDocument %}` Twig tag** for server-side document fetching on item pages.
- **PHP service** for programmatic use elsewhere in your Craft codebase.
- **Native plugin settings** (env-var aware via Craft's `EnvAttributeParserBehavior`) stored under `Settings → Plugins → Collections Proxy`.
- **CP search panel** for testing queries against the configured API from inside the control panel.

## Install

Either via Packagist (once published) or a VCS repository entry in your Craft project's `composer.json`:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/CogappLabs/craft-collections-proxy"
  }
]
```

Then:

```bash
composer require cogapp/craft-collections-proxy
php craft plugin/install collections-proxy
```

## Configure

Settings live at **Settings → Plugins → Collections Proxy** in the CP, or in `config/collections-proxy.php`:

```php
<?php

use craft\helpers\App;

return [
    'serverApiUrl' => App::env('COLLECTIONS_API_URL'),
    'publicApiUrl' => App::env('PUBLIC_COLLECTIONS_API_URL'),
    'index' => App::env('COLLECTIONS_INDEX'),
    'titleField' => 'title',
    'searchFields' => 'title,description*',
];
```

Values in this file are merged with CP-saved settings; the file wins.

## Twig tag

```twig
{% set settings = craft.app.plugins.getPlugin('collections-proxy').getSettings() %}
{% collectionDocument settings.index someDocumentId as doc %}

<h1>{{ attribute(doc, settings.titleField) ?? 'Untitled' }}</h1>
```

The tag fetches via the plugin's `ApiClient`, which calls:

```
GET {serverApiUrl}/api/{index}/{id}?fields={itemFields}
```

and expects the API to return the document `_source` as the body.

## PHP service

```php
use cogapp\collectionsproxy\Plugin;

$doc = Plugin::getInstance()->apiClient->getDocument('my-index', 'abc123');
$results = Plugin::getInstance()->apiClient->search('my-index', 'chair', 20, 1);
```

`search()` returns a normalised shape: `['results' => [...], 'totalResults' => int, 'took' => int]`.

## CP search panel

Visit **Collections Proxy** in the main CP nav for a simple search form that calls the configured API via `Craft.sendActionRequest`. Useful for smoke-testing the API connection without leaving Craft.

## Development

```bash
composer install
composer test       # phpunit — 14 tests, 39 assertions, MockHandler-based (no live HTTP)
composer phpstan    # phpstan level 8, no baseline
```

Or from a consuming DDEV project that bind-mounts this repo:

```bash
ddev exec bash -c "cd /var/www/craft-collections-proxy && vendor/bin/phpunit"
ddev exec bash -c "cd /var/www/craft-collections-proxy && composer phpstan"
```

## Expected API contract

The plugin assumes the backend speaks:

- `GET /api/:index?q=&perPage=&page=&fields=` → `{ hits: { hits: [{ _id, _source }], total: { value } }, took }`
- `GET /api/:index/:id?fields=` → the `_source` object directly

## License

MIT.
