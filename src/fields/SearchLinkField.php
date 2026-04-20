<?php

namespace cogapp\collectionsproxy\fields;

use cogapp\collectionsproxy\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Html;
use yii\db\Schema;

/**
 * Custom field type for picking a single Collections API document.
 *
 * Editors type a query, get live results (thumbnails + title + id), and
 * click to select. The field persists three values to its own multi-
 * column DB row: `documentId`, `documentTitle`, and `documentThumbnail`.
 * Persisting the thumbnail URL means the saved-document view renders
 * without having to re-hit the API on every edit-screen load.
 *
 * The search box talks to `SearchController::actionQuery` via
 * `Craft.sendActionRequest` (vanilla JS — no Sprig/htmx dependency).
 * The controller then delegates to `ApiClient::search()`, so the field
 * inherits the same backend + caching story as the Twig tags.
 */
class SearchLinkField extends Field
{
    /**
     * Per-field index override. Empty = use the plugin's global `index`
     * setting. Env-var aware via the field settings form.
     */
    public string $index = '';

    /**
     * Name of the `_source` field on each hit that holds a thumbnail URL
     * (e.g. `thumbnail_url`, `iiif_thumbnail_url`). Empty = hide
     * thumbnails in the search UI. No URL rewriting is performed — the
     * stored value is whatever the API returned.
     */
    public string $thumbnailField = '';

    public static function displayName(): string
    {
        return 'Collection Search Link';
    }

    public static function icon(): string
    {
        return 'link';
    }

    /**
     * Multi-column storage: each entry becomes a column in the content
     * table, named `field_{handle}_{key}`.
     *
     * @return array<string, string>
     */
    public static function dbType(): array
    {
        return [
            'documentId' => Schema::TYPE_STRING,
            'documentTitle' => Schema::TYPE_STRING,
            'documentThumbnail' => Schema::TYPE_TEXT,
        ];
    }

    /** @return array<array<mixed>> */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['index', 'thumbnailField'], 'string'];
        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        $plugin = Plugin::getInstance();
        $defaultIndex = $plugin?->getSettings()->index ?? '';

        /** @var \craft\web\View $view */
        $view = Craft::$app->getView();
        return $view->renderTemplate('collections-proxy/_search-link-field/settings', [
            'field' => $this,
            'defaultIndex' => $defaultIndex,
        ]);
    }

    /**
     * Resolve the active index — from the field setting, falling back
     * to the plugin's global index setting. Both are already env-parsed
     * by EnvAttributeParserBehavior on the settings model, so this just
     * picks whichever is non-empty.
     */
    private function resolveIndex(): string
    {
        if ($this->index !== '') {
            return $this->index;
        }
        return Plugin::getInstance()?->getSettings()->index ?? '';
    }

    /** @inheritdoc */
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (is_array($value)) {
            $documentId = (string) ($value['documentId'] ?? '');
            if ($documentId === '') {
                return null;
            }
            return [
                'documentId' => $documentId,
                'documentTitle' => (string) ($value['documentTitle'] ?? ''),
                'documentThumbnail' => (string) ($value['documentThumbnail'] ?? ''),
            ];
        }

        return $value;
    }

    /** @inheritdoc */
    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (is_array($value)) {
            return [
                'documentId' => $value['documentId'] ?? '',
                'documentTitle' => $value['documentTitle'] ?? '',
                'documentThumbnail' => $value['documentThumbnail'] ?? '',
            ];
        }

        return null;
    }

    /** @inheritdoc */
    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $documentId = '';
        $documentTitle = '';
        $documentThumbnail = '';

        if (is_array($value)) {
            $documentId = $value['documentId'] ?? '';
            $documentTitle = $value['documentTitle'] ?? '';
            $documentThumbnail = $value['documentThumbnail'] ?? '';
        }

        $index = $this->resolveIndex();

        $fieldId = Html::id($this->handle ?? 'search-link') . '-' . ($element->id ?? 'new');

        /** @var \craft\web\View $view */
        $view = Craft::$app->getView();
        return $view->renderTemplate('collections-proxy/_search-link-field/input', [
            'field' => $this,
            'fieldId' => $fieldId,
            'index' => $index,
            'thumbnailField' => $this->thumbnailField,
            'documentId' => $documentId,
            'documentTitle' => $documentTitle,
            'documentThumbnail' => $documentThumbnail,
            'actionUrl' => \craft\helpers\UrlHelper::actionUrl('collections-proxy/search/query'),
        ]);
    }
}
