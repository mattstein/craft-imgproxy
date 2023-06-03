<?php

namespace modules\imgproxy\variables;

use craft\elements\Asset;
use Imgproxy\Exception;
use Imgproxy\UrlBuilder;
use modules\imgproxy\models\ImgproxyTransform;
use modules\imgproxy\Module;

class ImgproxyVariable
{
    /**
     * @param string|Asset                         $source  An Asset or an absolute URL
     * @param array<string, bool|string|int|null>  $params  Transform parameters
     * @throws \Exception
     */
    public function transform(Asset|string $source, array $params): ImgproxyTransform
    {
        return Module::getInstance()->getTransform($source, $params);
    }

    /**
     * @throws Exception
     */
    public function getBuilder(): UrlBuilder
    {
        return Module::getInstance()->getBuilder();
    }
}
