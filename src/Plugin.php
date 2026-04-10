<?php

namespace cogapp\collectionsproxy;

use Craft;
use cogapp\collectionsproxy\models\Settings;
use cogapp\collectionsproxy\services\ApiClient;
use cogapp\collectionsproxy\web\twig\Extension as TwigExtension;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use craft\web\twig\variables\Cp;
use yii\base\Event;

/**
 * @property ApiClient $apiClient
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

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

        Craft::$app->onInit(function () {
            $this->registerTwigExtension();
            $this->registerCpUrlRules();
            $this->registerCpNav();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'collections-proxy/_settings',
            ['settings' => $this->getSettings()],
        );
    }

    private function registerTwigExtension(): void
    {
        Craft::$app->getView()->registerTwigExtension(new TwigExtension());
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
                ];
            },
        );
    }
}
