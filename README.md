# imgproxy Module

Craft CMS wrapper module for [crocodile2u/imgproxy-php](https://github.com/crocodile2u/imgproxy-php), aiming for 1:1 support with Craft’s native Asset transform parameters and making it possible to take advantage of imgproxy-only options.

## Setup

### Install the Module

```
composer require mattstein/craft-imgproxy
```

In `config/app.php`:

```php
// ...
return [
    // ...
    'modules' => [
        'imgproxy' => modules\imgproxy\Module::class,
    ],
    'bootstrap' => ['imgproxy'],
];
```

### Add Environment Variables

Add the required `IMGPROXY_URL` and optional `IMGPROXY_KEY` and `IMGPROXY_SALT` environment variables for your imgproxy instance.

```
IMGPROXY_URL=https://my-imgproxy.example
IMGPROXY_KEY=943b421c9eb07c830af81030552c86009268de4e532ba2ee2eab8247c6da0881
IMGPROXY_SALT=520f986b998545b4785e0defbc4f3c1203f22de2374a3d53cb7a7fe9fea309c5
```

## Templating

### `craft.imgproxy.transform()`

Attempts to honor Craft’s transform parameters to generate an imgproxy URL.

Example:

```twig
{% set transform = craft.imgproxy.transform(
  asset,
  { width: 300, height: 300, mode: 'crop' }
) %}
{{ tag('img', {
    src: transform.getUrl(),
    alt: asset.alt,
}) }}
```

### `craft.imgproxy.srcset()`

Generates multiple transform URLs based on the provided `sizes` array, returning a string ready for an HTML `srcset` image attribute. (Also follows Craft’s API.)

Example:

```twig
{% set transform = craft.imgproxy.transform(
  asset,
  { width: 300, height: 300, mode: 'crop' }
) %}
{{ tag('img', {
    src: transform.getUrl(),
    srcset: transform.srcset(['1x', '2x', '3x']),
    alt: asset.alt,
}) }}
```

### `craft.imgproxy.getBuilder()`

Returns an instance if `imgproxy-php`’s `UrlBuilder` class so you can work with it directly in templates. This exposes the entire API and does not attempt to translate transform parameters from Craft to imgproxy.

```twig
{% set url = craft.imgproxy.getBuilder()
    .build(asset.url, asset.width, asset.height)
    .useAdvancedMode()
    .setWidth(200)
    .setHeight(200)
%}

{% do url.options().withRotate(180) %}
{% do url.options().withPixelate(2) %}

{{ tag('img', {
    src: url.toString(),
    alt: '',
}) }}
```

## Programmatic Option Comparison

| Property             | Craft Transforms                      | imgproxy OSS                                                                           | imgproxy Pro                                                                                                                |
|----------------------|---------------------------------------|----------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------|
| Quality              | ✓                                     | ✓                                                                                      | ✓                                                                                                                           |
| Source Format        | ??                                    | `jpg`, `png`, `gif`, `webp`, `avif`, `ico`, `svg`, `heic`, `bmp`, `tiff`, `pdf`, `mp4` | `jpg`, `png`, `webp`, `avif`, `gif`, `ico`, `svg`, `heic`, `bmp`, `tiff`, `pdf`, `mp4`                                      |
| Output Format        | `jpg`, `png`, `gif`, `webp`, `avif`   | `jpg`, `png`, `gif`, `webp`, `avif`,  `ico`, `svg`\*, `bmp`, `tiff`, `mp4`             | `jpg`, `png`, `gif`, `webp`, `avif`, `ico`, `svg`\*, `bmp`, `tiff`, `mp4`                                                   |
| Crop Mode            | `crop`, `fit`, `stretch`, `letterbox` | `fit`, `fill`, `fill-down`, `force`, `auto`                                            | `fit`, `fill`, `fill-down`, `force`, `auto`                                                                                 |
| Named Presets        | ✓                                     | ✓                                                                                      | ✓                                                                                                                           |
| Fill                 | ✓                                     | ✓                                                                                      | ✓                                                                                                                           |
| DPR                  | -                                     | ✓                                                                                      | ✓                                                                                                                           |
| Filters              | -                                     | sharpen, pixelate, blur                                                                | background alpha, adjust, brightness, contrast, saturation, unsharpening, blur detections, draw detections, gradient, style |
| Filename             | ✓                                     | ✓                                                                                      | ✓                                                                                                                           |
| Skip Processing, Raw | -                                     | ✓                                                                                      | ✓                                                                                                                           |
| Zoom                 | -                                     | ✓                                                                                      | ✓                                                                                                                           |
| Rotate               | -                                     | ✓                                                                                      | ✓                                                                                                                           |
| Padding              | -                                     | ✓                                                                                      | ✓                                                                                                                           |
| Watermark            | -                                     | ✓                                                                                      | ✓                                                                                                                           |
| Automatic            | -                                     | -                                                                                      | crop, rotate, quality, object detection                                                                                     |
| PDF Page             | -                                     | -                                                                                      | ✓                                                                                                                           |
| Fallback Image URL   | -                                     | -                                                                                      | ✓                                                                                                                           |
| Strip metadata       | required                              | default (optional)                                                                     | default (optional)                                                                                                          |
| Max bytes            | -                                     | ✓                                                                                      | ✓                                                                                                                           |
| Trim                 | -                                     | ✓                                                                                      | ✓                                                                                                                           |
| Keep copyright       | -                                     | ✓                                                                                      | ✓                                                                                                                           |
| Return attachment    | -                                     | ✓                                                                                      | ✓                                                                                                                           |
| GIF to MP4           | -                                     | -                                                                                      | ✓                                                                                                                           |

[imgproxy supported source and output file formats](https://github.com/imgproxy/imgproxy/blob/master/docs/image_formats_support.md)

[server configuration](https://docs.imgproxy.net/configuration?id=server)
