<?php

namespace modules\imgproxy\variables;

use Imgproxy\Exception;
use Imgproxy\UrlBuilder;
use modules\imgproxy\Module;

class ImgproxyVariable
{
    /**
     * @throws \Exception
     */
    public function transform($source, $params): \modules\imgproxy\models\Imgproxy
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
