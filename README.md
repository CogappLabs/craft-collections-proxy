# Craft Collections Proxy

A Craft CMS 5 plugin that exposes three Twig tags and a `SearchLinkField` custom field for talking to any read-only HTTP API that speaks the Elasticsearch response shape.

The plugin handles transport, caching-friendly server-side fetches, and the CP authoring UI. It makes no assumptions about the shape of the documents — field names, URL rewriting, and IIIF proxying are all the consuming site's concern.

## What it gives you

- **`SearchLinkField`** — a custom field type that lets editors search the Collections API and store a link to a collection document (persists `documentId`, `documentTitle`, `documentThumbnail`). Per-field settings: `index` (defaults to plugin global) and `thumbnailField` (name of the `_source` field holding a thumbnail URL; empty = no thumbnails in the search UI). Vanilla JS, no Sprig.
- **`{% collectionDocument 'index' id as doc %}`** — Twig tag for server-rendered item pages.
- **`{% collectionDocuments 'index' ids[, fields] as docs %}`** — batch document fetch via `_msearch` + an `ids` query, returns an array keyed by ID.
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

Editable in the CP at **Settings → Plugins → Collections Proxy**:

- `serverApiUrl` — used by the Twig tags on server-side requests
- `publicApiUrl` — exposed to the browser (React / Searchkit, etc.)
- `index` — default index when a template doesn't pass an explicit one

Developer config (set via `config/collections-proxy.php` or env vars — not editable in the CP):

- `titleField` — which `_source` key the item page uses as a heading
- `itemFields` — comma-separated `?fields=` list for `{% collectionDocument %}` / `{% collectionDocuments %}`. Empty = request all fields.

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

Values in this file are merged with CP-saved settings; the file wins. URL / index values accept `$ENV_VAR` references via Craft's `EnvAttributeParserBehavior`.

## Twig tags

Single document — for item pages:

```twig
{% set settings = craft.app.plugins.getPlugin('collections-proxy').getSettings() %}
{% collectionDocument settings.index someDocumentId as doc %}

<h1>{{ attribute(doc, settings.titleField) ?? 'Untitled' }}</h1>
```

Batch document fetch — for listing templates that need N thumbnails in one round-trip:

```twig
{% collectionDocuments settings.index docIds, 'thumbnail_url,title' as docs %}
{% for id in docIds %}
  <img src="{{ docs[id].thumbnail_url ?? '' }}" alt="{{ docs[id].title ?? '' }}">
{% endfor %}
```

Search — for server-rendered fragments (e.g. Datastar SSE responses):

```twig
{% collectionSearch settings.index, 'Joshua Johnson', 5 as related %}
{% for hit in related.results %}
  <a href="{{ url('item/' ~ hit.id) }}">{{ hit.source.title }}</a>
{% endfor %}
```

All three tags hit the configured `serverApiUrl` backend; it must speak the Elasticsearch response shape documented below.

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

The plugin assumes the backend speaks three endpoints, all Elasticsearch-shaped:

- `GET /api/v1/:index?q=&perPage=&page=&fields=` — used by `{% collectionSearch %}` and `SearchController`
  → `{ hits: { hits: [{ _id, _source }], total: { value } }, took }`
- `GET /api/v1/:index/:id?fields=` — used by `{% collectionDocument %}`
  → the `_source` object directly
- `POST /api/v1/_msearch` — used by `{% collectionDocuments %}`. NDJSON body in the standard Elasticsearch format (header line + query line). The plugin sends `{"query":{"ids":{"values":[…]}}, "size":N, "_source":[…]}`.
  → `{ responses: [{ hits: { hits: [{ _id, _source }], total: { value } }, took }, …] }`

Any HTTP service that returns responses in these shapes will work — a thin Elasticsearch proxy (Hono, Elysia, Laravel, hand-rolled, etc.) or Elasticsearch / OpenSearch directly behind a trusted network boundary.

## License

MIT.
