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

    /** @inheritdoc */
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (is_array($value)) {
            $docId = $value['documentId'] ?? '';
            if ($docId !== '') {
                return [
                    'indexHandle' => $value['indexHandle'] ?? App::parseEnv($this->index) ?? '',
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
                'indexHandle' => $value['indexHandle'] ?? '',
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

        $index = App::parseEnv($this->index);
        if ($index === '' || $index === null) {
            $plugin = Plugin::getInstance();
            $index = $plugin ? App::parseEnv($plugin->getSettings()->index) : '';
        }

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
