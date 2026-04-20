# Craft Collections Proxy

A Craft CMS 5 plugin that exposes a lightweight HTTP client and Twig tag for a read-only Collections API that speaks the Elasticsearch / OpenSearch response shape.

Designed to sit in front of any of the [FAMSF Collections API](https://github.com/CogappLabs) flavours (Bun/Elysia on Railway, Laravel Octane on Railway, or Hono on Cloudflare Workers). All three speak the same endpoints, so you can swap between them by changing the `serverApiUrl` setting.

## What it gives you

- **`SearchLinkField`** — a custom field type that lets editors search the Collections API and store a link to a collection document (document ID + title + thumbnail URL). Index is configurable per-field; vanilla JS, no Sprig.
- **`{% collectionDocument 'index' id as doc %}`** — Twig tag for server-rendered item pages.
- **`{% collectionDocuments 'index' ids[, fields] as docs %}`** — batch document fetch (backed by `_mget`), returns an array keyed by ID.
- **`{% collectionSearch 'index', query[, perPage[, page]] as results %}`** — run a search; assigns `{results, totalResults, took}`.
- **Native plugin settings** (env-var aware via Craft's `EnvAttributeParserBehavior`) stored under `Settings → Plugins → Collections Proxy`.

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
    'itemFields' => '',
];
```

Values in this file are merged with CP-saved settings; the file wins.

## Twig tags

Single document — for item pages:

```twig
{% set settings = craft.app.plugins.getPlugin('collections-proxy').getSettings() %}
{% collectionDocument settings.index someDocumentId as doc %}

<h1>{{ attribute(doc, settings.titleField) ?? 'Untitled' }}</h1>
```

Batch document fetch — for listing templates that need N thumbnails in one round-trip:

```twig
{% collectionDocuments settings.index docIds, 'iiif_thumbnail_url,title' as docs %}
{% for id in docIds %}
  <img src="{{ docs[id].iiif_thumbnail_url ?? '' }}" alt="{{ docs[id].title ?? '' }}">
{% endfor %}
```

Search — for server-rendered fragments (e.g. Datastar SSE responses):

```twig
{% collectionSearch settings.index, 'Joshua Johnson', 5 as related %}
{% for hit in related.results %}
  <a href="{{ url('item/' ~ hit.id) }}">{{ hit.source.title }}</a>
{% endfor %}
```

All three tags hit the configured `serverApiUrl` backend; it must speak the Elasticsearch / OpenSearch response shape documented below.

## Development

```bash
composer install
composer test       # phpunit — MockHandler-based (no live HTTP, no Craft bootstrap)
composer phpstan    # phpstan level 8, no baseline
```

Or from a consuming DDEV project that bind-mounts this repo:

```bash
ddev exec bash -c "cd /var/www/craft-collections-proxy && vendor/bin/phpunit"
ddev exec bash -c "cd /var/www/craft-collections-proxy && composer phpstan"
```

## Expected API contract

The plugin assumes the backend speaks:

- `GET /api/v1/:index?q=&perPage=&page=&fields=` → `{ hits: { hits: [{ _id, _source }], total: { value } }, took }`
- `GET /api/v1/:index/:id?fields=` → the `_source` object directly
- `POST /api/v1/:index/_mget` with `{ ids: [...], fields: '...' }` → `{ docs: [{ _id, _source }, ...] }`

## License

MIT.
