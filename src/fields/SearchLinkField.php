<?php

namespace cogapp\collectionsproxy\fields;

use cogapp\collectionsproxy\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\App;
use craft\helpers\Html;
use yii\db\Schema;

/**
 * A field type that lets editors search the Collections API and store
 * a link to a document. Uses vanilla JS — no Sprig/htmx dependency.
 * Shares the same SearchController action as the CP search page and widget.
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
            'indexHandle' => Schema::TYPE_STRING,
            'documentId' => Schema::TYPE_STRING,
            'documentTitle' => Schema::TYPE_STRING,
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
     * to the plugin's global index setting.
     */
    private function resolveIndex(): string
    {
        $parsed = App::parseEnv($this->index);
        if (is_string($parsed) && $parsed !== '') {
            return $parsed;
        }
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return '';
        }
        $fromSettings = App::parseEnv($plugin->getSettings()->index);
        return is_string($fromSettings) ? $fromSettings : '';
    }

    /** @inheritdoc */
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (is_array($value)) {
            $docId = $value['documentId'] ?? '';
            if ($docId !== '') {
                return [
                    'indexHandle' => $this->resolveIndex(),
                    'documentId' => $docId,
                    'documentTitle' => $value['documentTitle'] ?? '',
                ];
            }
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
            ];
        }

        return null;
    }

    /** @inheritdoc */
    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $documentId = '';
        $documentTitle = '';

        if (is_array($value)) {
            $documentId = $value['documentId'] ?? '';
            $documentTitle = $value['documentTitle'] ?? '';
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
            'actionUrl' => \craft\helpers\UrlHelper::actionUrl('collections-proxy/search/query'),
        ]);
    }
}
