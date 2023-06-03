<?php

namespace modules\imgproxy\models;

use Craft;
use craft\elements\Asset;
use craft\errors\FsException;
use craft\helpers\App;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\Image as ImageHelper;
use FastImageSize\FastImageSize;
use Imgproxy\Exception;
use Imgproxy\Gravity;
use Imgproxy\Url;
use Imgproxy\UrlBuilder;
use yii\base\InvalidConfigException;

class ImgproxyTransform
{
    /**
     * @var Asset|string $source
     */
    private string|Asset $source;

    /**
     * @var string|Asset $sourceUrl
     */
    private string|Asset $sourceUrl;

    /**
     * @var array<string, bool|string|null> $params
     */
    private array $params;

    /**
     * @var int $width
     */
    private int $width;

    /**
     * @var int $height
     */
    private int $height;

    /**
     * @param string|Asset $source
     * @param array<string, bool|string|int|null> $params  Transform parameters
     *
     * @throws \ImagickException
     * @throws Exception
     * @throws InvalidConfigException
     * @throws FsException
     * @throws \yii\base\Exception
     * @throws \Exception
     */
    public function __construct(Asset|string $source, array $params = [])
    {
        self::checkConfig();

        $this->source = $source;
        $this->params = $params;
        $this->sourceUrl = $this->source instanceof Asset ? $source->url : (string)$source;

        $hasBothTargetDimensions = isset($params['width'], $params['height']);

        if ($hasBothTargetDimensions) {
            $this->width = $params['width'];
            $this->height = $params['height'];
        } else {
            $detectedDimensions = $this->getSourceDimensions();

            if (!$detectedDimensions) {
                throw new \Exception('Image dimensions are missing and could not be auto-detected.');
            }

            $sourceW = $detectedDimensions['width'];
            $sourceH = $detectedDimensions['height'];

            if (isset($params['width']) && !isset($params['height'])) {
                // Set width and calculate height
                $this->width = $params['width'];
                $this->height = $sourceH / ($sourceW / $this->width);
            }

            if (!isset($params['width']) && isset($params['height'])) {
                // set height and calculate width
                $this->height = $params['height'];
                $this->width = ($sourceW / $sourceH) * $this->height;
            }

            if (!isset($params['width']) && !isset($params['height'])) {
                // Default to source if we don’t have anything else
                $this->width = $sourceW;
                $this->height = $sourceH;
            }
        }
    }

    /**
     * @param array<string, bool|string|int|null|int[]> $params  Transform parameters
     * @throws Exception
     */
    public function transform(array $params): Url
    {
        $builder = self::getBuilder();

        $params = array_merge($this->params, $params);

        $width = $params['width'] ?? $this->width;
        $height = $params['height'] ?? $this->height;

        $url = $builder->build($this->sourceUrl, $width, $height);
        $url->useAdvancedMode();

        /**
         * Craft equivalents
         */
        if (isset($params['quality'])) {
            $url->options()->withQuality($params['quality']);
        } elseif (class_exists('Craft')) {
            $defaultQuality = Craft::$app->getConfig()->general->defaultImageQuality;
            $url->options()->withQuality($defaultQuality);
        }

        // https://docs.imgproxy.net/generating_the_url?id=format
        if (isset($params['format'])) {
            $url->options()->withFormat($params['format']);
            $url->setExtension($params['extension'] ?? $params['format']);
        }

        // https://docs.imgproxy.net/generating_the_url?id=resizing-type
        $craftResizeValue = $params['mode'] ?? 'crop';

        $resizeMap = [
            'crop' => 'fill',
            'fit' => 'fit',
            'stretch' => 'force',
        ];

        // can also be imgproxy-only `fill-down` or `auto`!
        $imgproxyTypes = [
            'fit',
            'fill',
            'fill-down',
            'force',
            'auto',
        ];

        $resizeType = $resizeMap[$craftResizeValue] ?? $craftResizeValue;

        if ($craftResizeValue === 'letterbox') {
            $resizeType = 'fit';
            $url->options()->withExtend();
        }

        $url->options()->withResizingType($resizeType);

        // focal point
        if ($this->source instanceof Asset) {
            $fp = $this->source->getFocalPoint();
            $url->options()->withGravity(Gravity::FOCUS_POINT, $fp['x'], $fp['y']);
        }

        if (isset($params['fill'])) {
            $hex = trim($params['fill'], '#');
            $url->options()->withBackgroundHex($hex);
        }

        if (isset($params['upscale'])) {
            if ($params['upscale']) {
                $url->options()->withEnlarge();
            }
        } else {
            $url->options()->withEnlarge();
        }

        if (isset($params['interlace'])) {
            // Can only be applied to PNG
            $url->options()->withPngOptions(true);
        }

        /**
         * Offered by Craft but not obvious to translate here:
         * - position
         */

        /**
         * Exclusive imgproxy options
         */
        if (isset($params['dpr'])) {
            $url->options()->withDpr($params['dpr']);
        }

        if (isset($params['blur'])) {
            $url->options()->withBlur($params['blur']);
        }

        if (isset($params['sharpen'])) {
            $url->options()->withSharpen($params['sharpen']);
        }

        if (isset($params['pixelate'])) {
            $url->options()->withPixelate($params['pixelate']);
        }

        if (
            (isset($params['autoRotate']) && $params['autoRotate']) ||
            (isset($params['auto-rotate']) && $params['auto-rotate']) ||
            (isset($params['autorotate']) && $params['autorotate'])
        ) {
            $url->options()->withAutoRotate();
        }

        if (isset($params['rotate'])) {
            $url->options()->withRotate($params['rotate']);
        }

        $cacheBusterValue = $params['cacheBuster'] ?? $params['cachebuster'] ?? null;
        if ($cacheBusterValue) {
            $url->options()->withCacheBuster($cacheBusterValue);
        }

        if (isset($params['filename'])) {
            $url->options()->withFilename($params['filename']);
        }

        if (isset($params['padding'])) {
            [$t, $r, $b, $l] = $params['padding'];
            $url->options()->withPadding($t, $r, $b, $l);
        }

        /**
         * To handle:
         * - skip processing
         * - watermark
         * - zoom
         * - raw
         * - all pro options!
         */

        return $url;
    }

    /**
     * Returns the URL of the desired transform, as opposed to the URL class that would
     * otherwise represent it.
     *
     * @param ?array<string, bool|string|int|null> $params  Transform parameters
     * @throws Exception
     */
    public function getUrl(?array $params = []): string
    {
        return $this->transform($params)
            ->toString();
    }

    /**
     * Returns a a UrlBuilder instance for direct interaction.
     * @throws Exception
     */
    public static function getBuilder(): UrlBuilder
    {
        self::checkConfig();

        return new UrlBuilder(
            App::env('IMGPROXY_URL'),
            App::env('IMGPROXY_KEY'),
            App::env('IMGPROXY_SALT'),
        );
    }

    /**
     * Generates variations on the original transform with the specified sizes.
     *
     * @param array<string> $sizes
     * @return string Comma-separated sizes string ready for a `srcset` HTML attribute
     * @throws Exception
     */
    public function getSrcset(array $sizes = []): string
    {
        // https://docs.craftcms.com/api/v4/craft-elements-asset.html#method-getsrcset
        // https://github.com/craftcms/cms/blob/main/src/elements/Asset.php#L1580-L1599

        if (!isset($this->params['width'])) {
            throw new Exception('Width must be specified before using `srcset`');
        }

        $urls = [];

        foreach ($sizes as $size) {
            // 1x or 1.0x or [width]w = unadjusted param set
            $descriptor = $size[strlen($size) - 1];
            $sizeValue = (float)rtrim($size, $descriptor);

            if (!in_array($descriptor, ['x', 'w'])) {
                throw new Exception(sprintf('Size descriptor `%s` must include `x` or `w`', $descriptor));
            }

            if (!is_numeric($sizeValue)) {
                throw new Exception(sprintf('Size value `%s` must be an int', $sizeValue));
            }

            if ($size === '1x' || $size === '1.0x' || $size === $this->width . 'w') {
                $urls[] = $this->getUrl();
            } else {
                $currentParams = $this->params;
                $currentParams['width'] = $descriptor === 'w' ? $sizeValue : round($this->width * $sizeValue);
                $currentParams['height'] = round($descriptor === 'w' ?
                    ($this->height * $currentParams['width']) / $this->width :
                    $this->height * $sizeValue
                );

                $sizedUrl = $this->getUrl($currentParams);

                $urls[] = $sizedUrl . ' ' . $sizeValue . $descriptor;
            }
        }

        return implode(', ', $urls);
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * @throws Exception
     */
    private static function checkConfig(): void
    {
        if (empty(App::env('IMGPROXY_URL'))) {
            // If we don’t have a base URL there’s no sense in doing anything else
            throw new Exception('An imgproxy instance URL is required.');
        }
    }

    /**
     * Attempts to detect the width and height of $this->source using several methods, returning
     * an array of `['width' => int, 'height' => int]` or `false` if the auto-detection was
     * unsuccessful.
     *
     * @return false|array<string, int>
     * @throws \ImagickException
     * @throws FsException
     * @throws \yii\base\Exception
     * @throws InvalidConfigException
     */
    private function getSourceDimensions(): false|array
    {
        $isAsset = $this->source instanceof Asset;

        /**
         * If we’ve got an Asset, first try to get dimensions with its stream.
         */
        if ($isAsset && [$w, $h] = ImageHelper::imageSizeByStream($this->source->getStream())) {
            return [
                'width' => $w,
                'height' => $h,
            ];
        }

        /**
         * Next, let’s try a similar approach with a library that supports more formats and
         * acts on plain URLs.
         */
        $fastImageSize = new FastImageSize();
        if ($fastImageResult = $fastImageSize->getImageSize($this->sourceUrl)) {
            return [
                'width' => $fastImageResult['width'],
                'height' => $fastImageResult['height'],
            ];
        }

        /**
         * If we have ImageMagick, see if it can determine the dimensions from the source URL.
         */
        $imageMagickAvailable = extension_loaded('imagick') &&
            method_exists(\Imagick::class, 'getImageSize');
        if ($imageMagickAvailable) {
            $imageMagick = new \Imagick($this->sourceUrl);
            if (($w = $imageMagick->getImageWidth()) && ($h = $imageMagick->getImageHeight())) {
                return [
                    'width' => $w,
                    'height' => $h,
                ];
            }
        }

        if ($isAsset) {
            /**
             * As a last-ditch effort, download the Asset and try checking it locally.
             */
            $tempPath = AssetsHelper::tempFilePath(pathinfo($this->source->filename, PATHINFO_EXTENSION));
            $stream = $this->source->getStream();
            $outputStream = fopen($tempPath, 'wb');

            $bytes = stream_copy_to_stream($stream, $outputStream);

            fclose($stream);
            fclose($outputStream);

            [$w, $h] = ImageHelper::imageSize($tempPath);

            if ($w !== 0 && $h !== 0) {
                return [
                    'width' => $w,
                    'height' => $h,
                ];
            }
        }

        // Couldn’t auto-detect :(
        return false;
    }
}
