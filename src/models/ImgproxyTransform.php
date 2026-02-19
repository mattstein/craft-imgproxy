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
     * @var string $sourceUrl
     */
    private string $sourceUrl;

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
     */
    public function __construct(Asset|string $source, array $params = [])
    {
        self::checkConfig();

        $this->source = $source;
        $this->params = $params;
        $this->sourceUrl = $this->source instanceof Asset ? $source->url : (string)$source;

        $hasBothTargetDimensions = isset($params['width'], $params['height']);
        $hasRatio = isset($params['ratio']);

        if ($hasBothTargetDimensions) {
            $this->width = $params['width'];
            $this->height = $params['height'];
        } elseif ($hasRatio && isset($params['width'])) {
            [$rW, $rH] = $this->parseRatio($params['ratio']);
            $this->width = $params['width'];
            $this->height = (int) round($params['width'] * $rH / $rW);
        } elseif ($hasRatio && isset($params['height'])) {
            [$rW, $rH] = $this->parseRatio($params['ratio']);
            $this->height = $params['height'];
            $this->width = (int) round($params['height'] * $rW / $rH);
        } else {
            $detectedDimensions = $this->getSourceDimensions();

            if (!$detectedDimensions) {
                throw new Exception('Image dimensions are missing and could not be auto-detected.');
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
                if ($hasRatio) {
                    // Ratio without explicit dimensions: preserve source width, derive height
                    [$rW, $rH] = $this->parseRatio($params['ratio']);
                    $this->width = $sourceW;
                    $this->height = (int) round($sourceW * $rH / $rW);
                } else {
                    // Default to source if we don't have anything else
                    $this->width = $sourceW;
                    $this->height = $sourceH;
                }
            }
        }
    }

    /**
     * Builds and returns a transform `Url` with the supplied parameters.
     *
     * This specifically maps Craft transform params to imgproxy params, then supports additional
     * options that are unique to imgproxy.
     *
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
        if ($this->source instanceof Asset && $fp = $this->source->getFocalPoint()) {
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
            (isset($params['auto_rotate']) && $params['auto_rotate']) ||
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
         * - trim
         * - background
         * - skip processing
         * - watermark
         * - zoom
         * - raw
         * - strip_metadata
         * - keep_copyright
         * - all pro options!
         */

        /**
         * imgproxy Pro options
         */

        if (isset($params['brightness'])) {
            $url->options()->withBrightness($params['brightness']);
        }

        if (isset($params['contrast'])) {
            $url->options()->withContrast($params['contrast']);
        }

        if (isset($params['saturation'])) {
            $url->options()->withSaturation($params['saturation']);
        }

        if (isset($params['page'])) {
            $url->options()->withPage($params['page']);
        }

        if (isset($params['resizingAlgorithm'], $params['resizing_algorithm'])) {
            $url->options()->withResizingAlgorithm(
                $params['resizingAlgorithm'] ?: $params['resizing_algorithm']
            );
        }

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
     * Takes the same options as `getUrl()` and returns a Base64-encoded data URI.
     *
     * @param array<string, mixed>|null $params
     * @throws Exception
     */
    public function getDataUri(?array $params = []): string
    {
        $image = file_get_contents($this->getUrl($params));
        $encoded = base64_encode($image);

        $format = $params['format'] ?? $this->params['format'] ?? 'jpg';

        return sprintf('data:image/%s;base64,%s', $format, $encoded);
    }

    /**
     * Returns a UrlBuilder instance for direct interaction.
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
     * Each entry in `$sizes` may be either:
     * - A plain descriptor string like `'800w'` or `'2x'`
     * - An associative array like `['width' => 800, 'ratio' => '1/1']`, where `ratio` is
     *   optional and overrides the transform-level ratio for that entry only.
     *
     * @param array<string|array<string, mixed>> $sizes
     * @return string Comma-separated sizes string ready for a `srcset` HTML attribute
     * @throws Exception
     */
    public function getSrcset(array $sizes = []): string
    {
        // https://docs.craftcms.com/api/v4/craft-elements-asset.html#method-getsrcset
        // https://github.com/craftcms/cms/blob/main/src/elements/Asset.php#L1580-L1599

        if (!isset($this->params['width']) && !isset($this->params['ratio'])) {
            throw new Exception('Width or ratio must be specified before using `srcset`');
        }

        $urls = [];
        $transformRatio = isset($this->params['ratio']) ? $this->parseRatio($this->params['ratio']) : null;

        foreach ($sizes as $size) {
            // Array form: ['width' => 800, 'ratio' => '1/1']
            if (is_array($size)) {
                $sizeWidth = $size['width'];
                $entryRatio = isset($size['ratio']) ? $this->parseRatio($size['ratio']) : $transformRatio;

                $currentParams = $this->params;
                $currentParams['width'] = $sizeWidth;
                $currentParams['height'] = $entryRatio
                    ? (int) round($sizeWidth * $entryRatio[1] / $entryRatio[0])
                    : (int) round(($this->height * $sizeWidth) / $this->width);

                $urls[] = $this->getUrl($currentParams) . ' ' . $sizeWidth . 'w';
                continue;
            }

            // 1x or 1.0x or [width]w = unadjusted param set
            $descriptor = $size[strlen($size) - 1];
            $sizeValue = (float)rtrim($size, $descriptor);

            if (!in_array($descriptor, ['x', 'w'])) {
                throw new Exception(sprintf('Size descriptor `%s` must include `x` or `w`', $descriptor));
            }

            if (!is_numeric($sizeValue)) {
                throw new Exception(sprintf('Size value `%s` must be an int', $sizeValue));
            }

            if ($size === '1x' || $size === '1.0x') {
                $urls[] = $this->getUrl();
            } elseif ($size === $this->width . 'w') {
                $urls[] = $this->getUrl() . ' ' . $sizeValue . $descriptor;
            } else {
                $currentParams = $this->params;
                $currentParams['width'] = $descriptor === 'w' ? $sizeValue : round($this->width * $sizeValue);

                if ($descriptor === 'w') {
                    $currentParams['height'] = $transformRatio
                        ? (int) round($sizeValue * $transformRatio[1] / $transformRatio[0])
                        : (int) round(($this->height * $currentParams['width']) / $this->width);
                } else {
                    $currentParams['height'] = (int) round($this->height * $sizeValue);
                }

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
     * Parses a ratio string like `16:9` or `3/2` into a `[float $w, float $h]` array.
     * @return array{float, float}
     * @throws Exception
     */
    private function parseRatio(string $ratio): array
    {
        // Plain float/integer (e.g. 1.777...)
        if (is_numeric($ratio)) {
            return [(float) $ratio, 1.0];
        }

        $separator = str_contains($ratio, '/') ? '/' : ':';
        $parts = explode($separator, $ratio, 2);

        if (count($parts) !== 2 || !is_numeric(trim($parts[0])) || !is_numeric(trim($parts[1]))) {
            throw new Exception(sprintf(
                'Invalid ratio `%s`. Expected format like `16:9`, `3/2`, or a float like `1.778`.', $ratio
            ));
        }

        return [(float) trim($parts[0]), (float) trim($parts[1])];
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

        if ($isAsset && !empty($this->source->width) && !empty($this->source->height)) {
            return [
                'width' => $this->source->width,
                'height' => $this->source->height,
            ];
        }

        /**
         * If we’ve got an Asset without both dimensions, first try to get dimensions with its stream.
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
