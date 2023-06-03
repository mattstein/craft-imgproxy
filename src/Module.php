<?php

namespace modules\imgproxy;

use Craft;
use Imgproxy\Exception;
use Imgproxy\UrlBuilder;
use craft\elements\Asset;
use craft\web\twig\variables\CraftVariable;
use modules\imgproxy\models\ImgproxyTransform;
use modules\imgproxy\variables\ImgproxyVariable;
use yii\base\Event;
use yii\base\Module as BaseModule;

/**
 * imgproxy module
 *
 * @method static Module getInstance()
 *
 * @property-read UrlBuilder $builder
 */
class Module extends BaseModule
{
    public function init(): void
    {
        Craft::setAlias('@modules/imgproxy', __DIR__);

        // Set the controllerNamespace based on whether this is a console or web request
        if (Craft::$app->request->isConsoleRequest) {
            $this->controllerNamespace = 'modules\\imgproxy\\console\\controllers';
        } else {
            $this->controllerNamespace = 'modules\\imgproxy\\controllers';
        }

        parent::init();

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function() {
            $this->attachEventHandlers();
        });
    }

    /**
     * Returns URL builder object ready with the supplied transform params.
     *
     * @param string|Asset                          $source
     * @param array<string, bool|string|int|null>   $params  Transform parameters
     * @return ImgproxyTransform
     * @throws \Exception
     */
    public function getTransform(Asset|string $source, ?array $params): ImgproxyTransform
    {
        return new ImgproxyTransform($source, $params);
    }

    /**
     * @throws Exception
     */
    public static function getBuilder(): UrlBuilder
    {
        return ImgproxyTransform::getBuilder();
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('imgproxy', ImgproxyVariable::class);
            }
        );
    }
}
