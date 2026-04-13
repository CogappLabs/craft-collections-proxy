<?php

namespace cogapp\collectionsproxy;

use Craft;
use cogapp\collectionsproxy\models\Settings;
use cogapp\collectionsproxy\services\ApiClient;
use cogapp\collectionsproxy\web\twig\Extension as TwigExtension;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Dashboard;
use craft\services\Fields;
use craft\web\UrlManager;
use craft\web\twig\variables\Cp;
use cogapp\collectionsproxy\fields\SearchLinkField;
use cogapp\collectionsproxy\widgets\SearchWidget;
use yii\base\Event;

/**
 * @property ApiClient $apiClient
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    /** @return array<string, array<string, mixed>> */
    public static function config(): array
    {
        return [
            'components' => [
                'apiClient' => ApiClient::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@cogapp/collectionsproxy', __DIR__);

        $this->controllerNamespace = 'cogapp\\collectionsproxy\\controllers';

        /** @var \craft\web\Application|\craft\console\Application $app */
        $app = Craft::$app;
        $app->onInit(function () {
            $this->registerTwigExtension();
            $this->registerCpUrlRules();
            $this->registerCpNav();
            $this->registerWidgets();
            $this->registerFieldTypes();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        /** @var \craft\web\View $view */
        $view = Craft::$app->getView();
        return $view->renderTemplate(
            'collections-proxy/_settings',
            ['settings' => $this->getSettings()],
        );
    }

    private function registerTwigExtension(): void
    {
        /** @var \craft\web\View $view */
        $view = Craft::$app->getView();
        $view->registerTwigExtension(new TwigExtension());
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['collections-proxy'] = ['template' => 'collections-proxy/search'];
                $event->rules['collections-proxy/search'] = ['template' => 'collections-proxy/search'];
            },
        );
    }

    private function registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = SearchLinkField::class;
            },
        );
    }

    private function registerWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = SearchWidget::class;
            },
        );
    }

    private function registerCpNav(): void
    {
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function (RegisterCpNavItemsEvent $event) {
                $event->navItems[] = [
                    'url' => 'collections-proxy',
                    'label' => 'Collections Proxy',
                    'icon' => '@appicons/search.svg',
                    'subnav' => [
                        'search' => [
                            'label' => 'Search',
                            'url' => 'collections-proxy',
                        ],
                        'settings' => [
                            'label' => 'Settings',
                            'url' => 'settings/plugins/collections-proxy',
                        ],
                    ],
                ];
            },
        );
    }
}
