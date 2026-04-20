<?php

namespace cogapp\collectionsproxy\fields;

use cogapp\collectionsproxy\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Html;
use yii\db\Schema;

/**
 * A field type that lets editors search the Collections API and store
 * a link to a document (id + title + thumbnail URL). Uses vanilla JS —
 * no Sprig/htmx dependency.
 */
class SearchLinkField extends Field
{
    /** The search index to search within (env-var aware). */
    public string $index = '';

    public static function displayName(): string
    {
        return 'Collection Search Link';
    }

    public static function icon(): string
    {
        return 'link';
    }

    /** @return array<string, string> */
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
        $rules[] = [['index'], 'string'];
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
            'documentId' => $documentId,
            'documentTitle' => $documentTitle,
            'documentThumbnail' => $documentThumbnail,
            'actionUrl' => \craft\helpers\UrlHelper::actionUrl('collections-proxy/search/query'),
        ]);
    }
}
