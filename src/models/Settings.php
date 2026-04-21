<?php

namespace cogapp\collectionsproxy\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;

/**
 * Plugin settings. All URL / index fields support environment variables
 * via Craft's standard $VAR syntax (handled by EnvAttributeParserBehavior).
 *
 * `serverApiUrl`, `publicApiUrl`, and `index` are editable in the CP
 * (Settings → Plugins → Collections Proxy). `titleField` and `itemFields`
 * are developer config and are expected to come from
 * `config/collections-proxy.php` or env vars.
 */
class Settings extends Model
{
    /** Server-side API URL (used by the Twig tags). */
    public string $serverApiUrl = '';

    /** Public API URL (exposed to the browser for client-side search). */
    public string $publicApiUrl = '';

    /** Default index name. */
    public string $index = '';

    /** Field used as the item title. Set via config file, not the CP. */
    public string $titleField = 'title';

    /** Comma-separated fields returned for item/document pages. Set via config file. Empty = all. */
    public string $itemFields = '';

    /**
     * Directory that holds `{% collectionEsSearch %}` PHP query files.
     * Accepts Craft path aliases (default `@config/queries`) — kept
     * outside `templates/` so query files aren't mistaken for Twig
     * templates.
     */
    public string $queryPath = '@config/queries';

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => [
                    'serverApiUrl',
                    'publicApiUrl',
                    'index',
                    'queryPath',
                ],
            ],
        ];
    }

    /** @return array<int, array<int|string, mixed>> */
    public function defineRules(): array
    {
        return [
            [['serverApiUrl', 'publicApiUrl', 'index', 'titleField', 'itemFields', 'queryPath'], 'string'],
            [['serverApiUrl', 'publicApiUrl', 'index'], 'required'],
        ];
    }
}
