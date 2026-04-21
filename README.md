# Craft Collections Proxy

A Craft CMS 5 plugin that exposes four Twig tags and a `SearchLinkField` custom field for talking to any read-only HTTP API that speaks the Elasticsearch response shape.

The plugin handles transport, caching-friendly server-side fetches, and the CP authoring UI. It makes no assumptions about the shape of the documents — field names, URL rewriting, and IIIF proxying are all the consuming site's concern.

## What it gives you

- **`SearchLinkField`** — a custom field type that lets editors search the Collections API and store a link to a collection document (persists `documentId`, `documentTitle`, `documentThumbnail`). Per-field settings: `index` (defaults to plugin global) and `thumbnailField` (name of the `_source` field holding a thumbnail URL; empty = no thumbnails in the search UI). Vanilla JS, no Sprig.
- **`{% collectionDocument 'index' id as doc %}`** — Twig tag for server-rendered item pages.
- **`{% collectionDocuments 'index' ids[, fields] as docs %}`** — batch document fetch via `_msearch` + an `ids` query, returns an array keyed by ID.
- **`{% collectionSearch 'index', query[, perPage[, page]] as results %}`** — run a `?q=` text search; assigns `{results, totalResults, took}`.
- **`{% collectionEsSearch 'index', queryOrBody[, params] as results %}`** _(experimental)_ — run a full Elasticsearch query body (transported via `POST /api/v1/_msearch`); assigns `{results, totalResults, took, aggregations}`. The second arg is either a PHP query-file name (loaded from `queryPath`) or an inline Twig hash used as the ES body. Signature and returned shape may change without a deprecation cycle until this moves out of experimental.
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
- `queryPath` — directory that holds `{% collectionEsSearch %}` PHP query files (default `@config/queries` — outside `templates/` so query files aren't mistaken for Twig)

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

### Experimental: `{% collectionEsSearch %}`

**This tag is experimental.** It works, but the signature, the `queryPath` default, and the returned shape may change without a deprecation cycle while we settle on conventions. Pin a specific plugin version if you rely on it in production.

ES query body — when `q=` isn't enough (need filters, aggs, sort, etc.). Two authoring styles:

**Inline Twig hash** — the body is written directly in the tag call:

```twig
{% set q = craft.app.request.getParam('q') %}
{% collectionEsSearch settings.index, {
  query: {
    bool: {
      must: q ? [{ multi_match: { query: q, fields: ['title^3', 'artist^2'] } }] : [{ match_all: {} }],
      filter: [{ term: { department: 'painting' } }]
    }
  },
  aggs: { medium: { terms: { field: 'medium.keyword', size: 20 } } },
  size: 24
} as results %}

{{ results.totalResults }} objects
{% for hit in results.results %}
  <a href="{{ url('object/' ~ hit.id) }}">{{ hit.source.title }}</a>
{% endfor %}
{% for bucket in results.aggregations.medium.buckets %}
  {{ bucket.key }} ({{ bucket.doc_count }})
{% endfor %}
```

**PHP query file** — for queries complex enough to benefit from conditionals, helpers, and PHPStan types. Put a file at `{queryPath}/<name>.php` that returns either a `callable(array $params): array` or an `array`:

```php
<?php
// config/queries/objects-search.php
return static function (array $params): array {
    $q = $params['q'] ?? '';
    return [
        'query' => [
            'bool' => [
                'must' => $q !== ''
                    ? [['multi_match' => ['query' => $q, 'fields' => ['title^3', 'artist^2']]]]
                    : [['match_all' => (object) []]],
                'filter' => array_map(
                    fn ($f) => ['term' => [$f['field'] => $f['value']]],
                    $params['filters'] ?? [],
                ),
            ],
        ],
        'aggs' => ['medium' => ['terms' => ['field' => 'medium.keyword', 'size' => 20]]],
        'size' => $params['size'] ?? 24,
        'from' => (($params['page'] ?? 1) - 1) * ($params['size'] ?? 24),
    ];
};
```

Then in Twig, pass the file's basename (no `.php`) plus a params hash:

```twig
{% collectionEsSearch settings.index, 'objects-search', {
  q: craft.app.request.getParam('q'),
  filters: activeFilters,
  page: currentPage,
  size: 24
} as results %}
```

The loader reads `queryPath` from plugin settings (default `@config/queries` — outside `templates/` so PHP query files aren't mistaken for Twig templates). Query names can't contain `..` segments or leading slashes/dots.

On any failure (missing file, wrong return type, HTTP error, ES error) the tag logs and returns the empty shape `{results: [], totalResults: 0, took: 0, aggregations: {}}` — templates never have to null-check.

All tags hit the configured `serverApiUrl` backend; it must speak the Elasticsearch response shape documented below.

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
- `POST /api/v1/_msearch` — used by `{% collectionDocuments %}` and `{% collectionEsSearch %}`. NDJSON body in the standard Elasticsearch format (header line + query line). `{% collectionDocuments %}` sends `{"query":{"ids":{"values":[…]}}, "size":N, "_source":[…]}`; `{% collectionEsSearch %}` sends whatever body the PHP query file / inline hash produces.
  → `{ responses: [{ hits: { hits: [{ _id, _source, _score }], total: { value } }, took, aggregations }, …] }`

Any HTTP service that returns responses in these shapes will work — a thin Elasticsearch proxy (Hono, Elysia, Laravel, hand-rolled, etc.) or Elasticsearch / OpenSearch directly behind a trusted network boundary.

## License

MIT.
