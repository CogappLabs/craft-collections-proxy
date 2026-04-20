<?php

namespace cogapp\collectionsproxy;

use Craft;
use cogapp\collectionsproxy\fields\SearchLinkField;
use cogapp\collectionsproxy\models\Settings;
use cogapp\collectionsproxy\services\ApiClient;
use cogapp\collectionsproxy\web\twig\Extension as TwigExtension;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use yii\base\Event;

/**
 * Entry point for the Collections Proxy plugin.
 *
 * Responsibilities:
 *  - register the shared `apiClient` Yii component (the internal HTTP client)
 *  - register the `@cogapp/collectionsproxy` path alias for template + asset lookups
 *  - register the Twig extension (which exposes the three tags)
 *  - register the `SearchLinkField` field type
 *  - expose CP settings for serverApiUrl / publicApiUrl / index
 *
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
}
