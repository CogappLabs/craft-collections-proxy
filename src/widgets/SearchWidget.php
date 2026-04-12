<?php

namespace cogapp\collectionsproxy\widgets;

use Craft;
use craft\base\Widget;
use cogapp\collectionsproxy\Plugin;

/**
 * Dashboard widget: a compact search box that queries the Collections API
 * and displays results inline. Uses the same SearchController action as
 * the full CP search panel.
 */
class SearchWidget extends Widget
{
    public string $index = '';

    public static function displayName(): string
    {
        return Craft::t('collections-proxy', 'Collection Search');
    }

    public static function icon(): ?string
    {
        return '@appicons/search.svg';
    }

    protected static function allowMultipleInstances(): bool
    {
        return false;
    }

    public function getTitle(): ?string
    {
        return self::displayName();
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
        return $view->renderTemplate(
            'collections-proxy/_widgets/search-settings',
            [
                'widget' => $this,
                'defaultIndex' => $defaultIndex,
            ],
        );
    }

    public function getBodyHtml(): ?string
    {
        $plugin = Plugin::getInstance();
        $index = $this->index ?: ($plugin?->getSettings()->index ?? '');

        /** @var \craft\web\View $view */
        $view = Craft::$app->getView();
        return $view->renderTemplate(
            'collections-proxy/_widgets/search-body',
            [
                'actionUrl' => \craft\helpers\UrlHelper::actionUrl('collections-proxy/search/query'),
                'index' => \craft\helpers\App::parseEnv($index),
            ],
        );
    }
}
