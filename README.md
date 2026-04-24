# Craft Collections Proxy

A Craft CMS 5 plugin that exposes four Twig tags and a `SearchLinkField` custom field for talking to any read-only HTTP API that speaks the Elasticsearch response shape.

The plugin handles transport, server-side fetches, and the CP authoring UI. It makes no assumptions about the shape of the documents — field names, URL rewriting, and IIIF proxying are all the consuming site's concern.

## Contents

- [What it gives you](#what-it-gives-you)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Multi-environment setup](#multi-environment-setup)
- [Verifying installation](#verifying-installation)
- [Using `SearchLinkField`](#using-searchlinkfield)
- [Twig tags](#twig-tags)
- [Experimental: `collectionEsSearch`](#experimental-collectionessearch)
- [Expected API contract](#expected-api-contract)
- [Development](#development)
- [License](#license)

## What it gives you

- **`SearchLinkField`** — a custom field type that lets editors search the Collections API and store a link to a collection document (persists `documentId`, `documentTitle`, `documentThumbnail`). Per-field settings: `index` (defaults to plugin global), `thumbnailField` (name of the `_source` field holding a thumbnail URL; empty = no thumbnails in the search UI), and `titleField` (name of the `_source` field to show as each result's title; empty = fall back to the plugin's global `titleField`, then `title`). Vanilla JS, no Sprig.
- **`{% collectionDocument 'index' id as doc %}`** — Twig tag for server-rendered item pages.
- **`{% collectionDocuments 'index' ids[, fields] as docs %}`** — batch document fetch via `_msearch` + an `ids` query, returns an array keyed by ID.
- **`{% collectionSearch 'index', query[, perPage[, page]] as results %}`** — run a `?q=` text search; assigns `{results, totalResults, took}`.
- **`{% collectionEsSearch 'index', queryOrBody[, params] as results %}`** _(experimental)_ — run a full Elasticsearch query body (transported via `POST /api/v1/_msearch`); assigns `{results, totalResults, took, aggregations}`. See the [experimental section](#experimental-collectionessearch).
- **Native plugin settings** (env-var aware via Craft's `EnvAttributeParserBehavior`) stored under `Settings → Plugins → Collections Proxy`.

## Requirements

- PHP **8.3** or newer
- Craft CMS **^5.0**
- A read-only HTTP backend that speaks the [expected API contract](#expected-api-contract) — typically Elasticsearch / OpenSearch behind a thin proxy, or any service that produces the same response shape

## Installation

This plugin isn't on Packagist yet — install via a VCS repository entry in your Craft project's `composer.json`.

**1. Add the repository:**

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/CogappLabs/craft-collections-proxy"
    }
  ]
}
```

**2. Require the package:**

```bash
composer require "cogapp/craft-collections-proxy:^1.0"
```

Pin to a tagged version (`^1.0`) for anything you care about reproducing — `dev-main` pulls the latest commit on every `composer update`, which is fine for active development but surprising in long-running envs.

**3. Install the plugin in Craft:**

```bash
php craft plugin/install collections-proxy
```

The plugin has a one-off install step (it registers `SearchLinkField` and shows a Settings → Plugins entry); `composer require` alone isn't enough.

## Configuration

Three layers, applied in this order (later wins):

1. Defaults baked into `src/models/Settings.php`
2. Values saved via the CP at **Settings → Plugins → Collections Proxy**
3. Values in `config/collections-proxy.php` — **always wins**

For anything that differs per environment you almost certainly want layer 3 (driven by env vars), not the CP, so `.env` / `wrangler.toml` / Railway variables stay the source of truth.

### Settings

| Setting | Type | Default | Where it's used |
|---|---|---|---|
| `serverApiUrl` | URL | _(required)_ | Base URL for **server-side** fetches (the Twig tags). Safe to be an internal-only hostname. |
| `publicApiUrl` | URL | _(required)_ | Base URL intended for **browser** use (React, Searchkit). The plugin itself only reads this via `getPublicApiUrl()`; you forward it to the frontend via a data attribute. |
| `index` | string | _(required)_ | Default index name when a Twig tag or `SearchLinkField` doesn't pass one explicitly. |
| `titleField` | string | `title` | Which `_source` key to use as the document title — consumed by item-page templates and by `SearchLinkField` (as the fallback when the per-field `titleField` is blank). Typically set in `config/collections-proxy.php`. |
| `itemFields` | string | `''` (all) | Comma-separated `fields=` whitelist for `{% collectionDocument %}` / `{% collectionDocuments %}`. Narrowing this lets you take advantage of the backend's own allow-list. |
| `queryPath` | path | `@config/queries` | Directory that holds `{% collectionEsSearch %}` PHP query files. _Experimental._ |

### `config/collections-proxy.php`

The canonical pattern — one file that reads everything from env, with a safe fallback for local dev:

```php
<?php

use craft\helpers\App;

// Fall back to a deployed dev backend when no local API is configured,
// so `ddev start` / `craft serve` is usable with zero extra setup.
$publicApiUrl = App::env('COLLECTIONS_API_URL') ?: 'https://collections-api.example.com';

// Inside Docker/DDEV, `localhost` in the host isn't reachable from the
// container. Rewrite so server-side Twig fetches hit the host bridge.
$serverApiUrl = str_replace('localhost', 'host.docker.internal', $publicApiUrl);

return [
    'serverApiUrl' => $serverApiUrl,
    'publicApiUrl' => $publicApiUrl,
    'index' => App::env('COLLECTIONS_INDEX') ?: '',
    'titleField' => App::env('COLLECTIONS_TITLE_FIELD') ?: 'title',
    'itemFields' => App::env('COLLECTIONS_ITEM_FIELDS') ?: '',
    'queryPath' => '@config/queries',
];
```

URL / index values in this file also honour `$VAR` references via Craft's `EnvAttributeParserBehavior`, so you can write `'serverApiUrl' => '$COLLECTIONS_API_URL'` instead of `App::env(...)` if you prefer. Pick one style and stick with it across settings.

### `serverApiUrl` vs `publicApiUrl`

These are almost always the same URL, but separated so you can:

- **Point them at different hosts** when server-side fetches should use an internal-only endpoint (faster, no TLS overhead, bypasses CDN caching) while the browser hits a public one.
- **Rewrite the server URL for Docker** (as the example above does) — containers can't resolve `localhost` but can reach `host.docker.internal`.

If you're not doing either of those, set both to the same value.

## Multi-environment setup

Three environments, same shape — just different env vars.

### Local dev (DDEV / Docker)

**Option A — Point at a deployed dev backend** (simplest, no local services to run):

```bash
# .ddev/config.yaml → web_environment, or .env
COLLECTIONS_API_URL=https://dev-api.example.com
COLLECTIONS_INDEX=my-index-dev
```

The `host.docker.internal` rewrite in the example `config/collections-proxy.php` is a no-op here (no `localhost` to replace), so both `serverApiUrl` and `publicApiUrl` become `https://dev-api.example.com`.

**Option B — Run the backend locally** (e.g. `wrangler dev` on port 8787, Bun on 3000):

```bash
COLLECTIONS_API_URL=https://localhost:8787
COLLECTIONS_INDEX=my-index-dev
```

The rewrite turns `serverApiUrl` into `https://host.docker.internal:8787` so the Craft container can reach the backend running on your host machine. `publicApiUrl` stays as `https://localhost:8787` for the browser, which is on the host. TLS verification is skipped for `localhost`, `127.0.0.1`, and `host.docker.internal` so self-signed / mkcert certs don't blow up.

### PR / preview environments

Preview envs (Railway PR apps, Vercel preview deployments, Upsun preview branches, etc.) typically want to share a **staging backend** rather than spin up a dedicated one per PR — no data to seed, no index to reindex, same results as production minus whatever frontend changes are in the PR.

Set the same env vars at the **platform level** (the default for all PR apps) rather than per-branch:

```
COLLECTIONS_API_URL=https://staging-api.example.com
COLLECTIONS_INDEX=my-index-staging
```

Every PR app inherits these automatically; you only override for PRs that genuinely need a different backend (e.g. a mapping change that requires a one-off index).

If your platform supports **branch-specific overrides** (Railway environments, Vercel env-var scoping) you can promote a PR to talk to a production-like index just by setting `COLLECTIONS_INDEX` on that one PR, without touching code.

### Production

Set env vars on the hosting platform directly. Never commit production URLs or index names to the repo.

```
COLLECTIONS_API_URL=https://api.example.com
COLLECTIONS_INDEX=my-index-prod
```

If you're running multiple interchangeable backend flavours (e.g. a Cloudflare Workers proxy, a Bun proxy, and a Laravel proxy — all exposing the same endpoints), keep `COLLECTIONS_API_URL` pointed at whichever is live and flip by updating the single variable. Nothing in the plugin needs redeploying.

### Multi-flavour / A-B testing

If you want the browser to hit one backend while server-side Twig hits another (for cache busting, warm-up, or canary testing):

```
SERVER_COLLECTIONS_API_URL=https://fast-internal.example.com
PUBLIC_COLLECTIONS_API_URL=https://public-cdn.example.com
```

```php
return [
    'serverApiUrl' => App::env('SERVER_COLLECTIONS_API_URL'),
    'publicApiUrl' => App::env('PUBLIC_COLLECTIONS_API_URL'),
    // ...
];
```

## Verifying installation

**1. Health check** — hit the backend directly to confirm the URL + TLS are right:

```bash
curl -sS "$COLLECTIONS_API_URL/api/v1/health"
# → {"status":"ok"}
```

**2. Index check** — confirm the default index exists and has records:

```bash
curl -sS "$COLLECTIONS_API_URL/api/v1/$COLLECTIONS_INDEX?perPage=1" | head -c 200
# → {"took":…,"hits":{"total":{"value":…},…}}
```

**3. Smoke-test a Twig tag** — drop this into any template and load the page:

```twig
{% set s = craft.app.plugins.getPlugin('collections-proxy').getSettings() %}
{% collectionSearch s.index, 'test', 1 as r %}
<pre>{{ r.totalResults }} hits in {{ r.took }}ms</pre>
```

If you see `0 hits in 0ms` and no exceptions, the plugin couldn't reach the backend — check `storage/logs/web-*.log` for the actual error (HTTP status, timeout, DNS). The tags never throw at render time; they log and return the empty shape.

## Using `SearchLinkField`

Add **Collection Search Link** as a custom field in the CP (Settings → Fields). The field stores a reference to one document from the Collections API — editors type a query, get live thumbnail + title results, and click to select.

### Per-field settings

| Setting | Falls back to | Purpose |
|---|---|---|
| **Index** | plugin-global `index` | Which backend index this field searches. Override for fields that should pick from a different index than the site default (e.g. a `people` field alongside an `artworks` field). Supports env vars. |
| **Thumbnail field** | _(blank = no thumbnails)_ | Name of the `_source` key that holds each document's thumbnail URL (e.g. `thumbnail_url`, `iiif_thumbnail_url`). The plugin does no URL rewriting — whatever the API returns is stored and displayed. |
| **Title field** | plugin-global `titleField`, then `title` | Name of the `_source` key to display as each result's title in the search UI and on the saved-document card. |

### What gets stored

The field persists three columns alongside your element (`field_{handle}_{key}` naming):

- `documentId` — the `_id` of the selected document
- `documentTitle` — a snapshot of the title at the time of selection
- `documentThumbnail` — a snapshot of the thumbnail URL (blank if no `thumbnailField` is configured)

The snapshots mean the saved-document card renders on edit screens without re-hitting the API every time.

### Rendering the stored value

```twig
{% set link = entry.collectionItem %}
{% if link and link.documentId %}
  <a href="{{ url('item/' ~ link.documentId) }}">
    {% if link.documentThumbnail %}
      <img src="{{ link.documentThumbnail }}" alt="">
    {% endif %}
    <span>{{ link.documentTitle }}</span>
  </a>
{% endif %}
```

If you need the full live document (not just the stored snapshot — e.g. you want fields that weren't saved at selection time), pair `SearchLinkField` with `{% collectionDocument %}`:

```twig
{% set link = entry.collectionItem %}
{% if link and link.documentId %}
  {% collectionDocument settings.index link.documentId as doc %}
  {# doc now has the full _source — render whatever you need #}
{% endif %}
```

### Permissions and security

The search box posts to `actions/collections-proxy/search/query`, gated by `requirePermission('accessCp')`. Non-CP users can't hit it. The `index` param is validated against a strict character allow-list before being sent downstream.

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

## Experimental: `collectionEsSearch`

**This tag is experimental.** It works, but the signature, the `queryPath` default, and the returned shape may change without a deprecation cycle while we settle on conventions. Pin a specific plugin version (`^1.0`) if you rely on it in production.

Full Elasticsearch query body — when `q=` isn't enough (need filters, aggs, sort, etc.). Two authoring styles:

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

## Expected API contract

The plugin assumes the backend speaks these endpoints, all Elasticsearch-shaped:

- `GET /api/v1/:index?q=&perPage=&page=&fields=` — used by `{% collectionSearch %}` and `SearchController`
  → `{ hits: { hits: [{ _id, _source }], total: { value } }, took }`
- `GET /api/v1/:index/:id?fields=` — used by `{% collectionDocument %}`
  → the `_source` object directly
- `POST /api/v1/_msearch` — used by `{% collectionDocuments %}` and `{% collectionEsSearch %}`. NDJSON body in the standard Elasticsearch format (header line + query line). `{% collectionDocuments %}` sends `{"query":{"ids":{"values":[…]}}, "size":N, "_source":[…]}`; `{% collectionEsSearch %}` sends whatever body the PHP query file / inline hash produces.
  → `{ responses: [{ hits: { hits: [{ _id, _source, _score }], total: { value } }, took, aggregations }, …] }`

Any HTTP service that returns responses in these shapes will work — a thin Elasticsearch proxy (Hono, Elysia, Laravel, hand-rolled, etc.) or Elasticsearch / OpenSearch directly behind a trusted network boundary.

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

## License

MIT — see [LICENSE](LICENSE).
