<?php

namespace cogapp\collectionsproxy\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;

/**
 * Plugin settings. All URL / index fields support environment variables
 * via Craft's standard $VAR syntax (handled by EnvAttributeParserBehavior).
 */
class Settings extends Model
{
    /** Server-side API URL (used by the Twig tag for item pages). */
    public string $serverApiUrl = '';

    /** Public API URL (exposed to the browser for client-side Searchkit). */
    public string $publicApiUrl = '';

    /** Default index name. */
    public string $index = '';

    /** Field used as the item title on item pages and in the search UI. */
    public string $titleField = 'title';

    /** Comma-separated fields returned for item/document pages. Empty = all. */
    public string $itemFields = '';

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => [
                    'serverApiUrl',
                    'publicApiUrl',
                    'index',
                ],
            ],
        ];
    }

    /** @return array<int, array<int|string, mixed>> */
    public function defineRules(): array
    {
        return [
            [['serverApiUrl', 'publicApiUrl', 'index'], 'string'],
            [['titleField', 'itemFields'], 'string'],
            [['serverApiUrl', 'publicApiUrl', 'index'], 'required'],
            [['serverApiUrl', 'publicApiUrl'], 'url', 'validSchemes' => ['http', 'https']],
        ];
    }
}
