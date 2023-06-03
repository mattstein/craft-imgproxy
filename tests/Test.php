<?php

use modules\imgproxy\Module;
use modules\imgproxy\models\ImgproxyTransform;

it('throws an exception for missing imgproxy URL', function() {
    Module::getBuilder();
})->throws(Exception::class, 'An imgproxy instance URL is required.');

it('transforms simple URLs', function() {
    define('IMGPROXY_URL', 'https://imgproxy.tld');

    $transform = new ImgproxyTransform('https://foo.tld/bar.jpg', [
        'width' => 800,
        'height' => 600,
    ]);

    expect($transform->getUrl())
        ->toEqual('https://imgproxy.tld/insecure/w:800/h:600/rt:fill/g:sm/el:1/aHR0cHM6Ly9mb28udGxkL2Jhci5qcGc.jpg');
});

it('securely transforms simple URLs', function() {
    define('IMGPROXY_KEY', '48a0e865325477962f1d13bc9727cc5553e0ff192959ce191f6fd651630ef49b');
    define('IMGPROXY_SALT', 'e5e2b856fb23dafd81c05e7c9e05b99d85f012c939a1f5ac19be6e05925626d2');

    $transform = new ImgproxyTransform('https://foo.tld/bar.jpg', [
        'width' => 800,
        'height' => 600
    ]);

    expect($transform->getUrl())
        ->toEqual('https://imgproxy.tld/Y4ZryrU_4G_pazAKuQOmtt2TQYy5fFgPRgTkqkIIrPU/w:800/h:600/rt:fill/g:sm/el:1/aHR0cHM6Ly9mb28udGxkL2Jhci5qcGc.jpg');
});

it('generates expected srcset for URL transform', function() {
    $transform = new ImgproxyTransform('https://foo.tld/bar.jpg', [
        'width' => 800,
        'height' => 600
    ]);

    expect($transform->getSrcset(['1x', '2x', '3x']))
        ->toEqual('https://imgproxy.tld/Y4ZryrU_4G_pazAKuQOmtt2TQYy5fFgPRgTkqkIIrPU/w:800/h:600/rt:fill/g:sm/el:1/aHR0cHM6Ly9mb28udGxkL2Jhci5qcGc.jpg, https://imgproxy.tld/S1D30exb7jZqr__xMCo1YRHyIVz1OZ8GpLrzvqQlzUk/w:1600/h:1200/rt:fill/g:sm/el:1/aHR0cHM6Ly9mb28udGxkL2Jhci5qcGc.jpg 2x, https://imgproxy.tld/J-tXZxQX7OfqqK6VTxDx2y1o-9NX8OssA-yTCV8bAvM/w:2400/h:1800/rt:fill/g:sm/el:1/aHR0cHM6Ly9mb28udGxkL2Jhci5qcGc.jpg 3x');

    expect($transform->getSrcset(['1.5x', '2.75x', '3.25x']))
        ->toEqual('https://imgproxy.tld/xz8fxPiWE0JFYmyH1Dq6syanDKLmmpYTlRa4dAU92d0/w:1200/h:900/rt:fill/g:sm/el:1/aHR0cHM6Ly9mb28udGxkL2Jhci5qcGc.jpg 1.5x, https://imgproxy.tld/LkhK296CoGv1YssunCHerKlC-MAV72hlGZtOms95Mug/w:2200/h:1650/rt:fill/g:sm/el:1/aHR0cHM6Ly9mb28udGxkL2Jhci5qcGc.jpg 2.75x, https://imgproxy.tld/9H-xVR_B8MIUn8KrX2FBeudjxx6l8Qth2GIxd6QmNDY/w:2600/h:1950/rt:fill/g:sm/el:1/aHR0cHM6Ly9mb28udGxkL2Jhci5qcGc.jpg 3.25x');

    expect($transform->getSrcset(['800w', '1600w', '2400w']))
        ->toEqual('https://imgproxy.tld/Y4ZryrU_4G_pazAKuQOmtt2TQYy5fFgPRgTkqkIIrPU/w:800/h:600/rt:fill/g:sm/el:1/aHR0cHM6Ly9mb28udGxkL2Jhci5qcGc.jpg, https://imgproxy.tld/S1D30exb7jZqr__xMCo1YRHyIVz1OZ8GpLrzvqQlzUk/w:1600/h:1200/rt:fill/g:sm/el:1/aHR0cHM6Ly9mb28udGxkL2Jhci5qcGc.jpg 1600w, https://imgproxy.tld/J-tXZxQX7OfqqK6VTxDx2y1o-9NX8OssA-yTCV8bAvM/w:2400/h:1800/rt:fill/g:sm/el:1/aHR0cHM6Ly9mb28udGxkL2Jhci5qcGc.jpg 2400w');
});

it('auto-detects missing dimension', function() {
    $transform = new ImgproxyTransform('https://files.mattstein.com/dapper-doo.jpg', [
        'width' => 800,
    ]);

    expect($transform->getUrl())
        ->toEqual('https://imgproxy.tld/kPCWACzYzI0Qk4mPcFSjqbMV4TEByX5NXDcxInDw2gM/w:800/h:800/rt:fill/g:sm/el:1/aHR0cHM6Ly9maWxlcy5tYXR0c3RlaW4uY29tL2RhcHBlci1kb28uanBn.jpg');
});

it('throws an exception for missing dimensions that can’t be detected', function() {
    $transform = new ImgproxyTransform('https://foo.tld/bar.jpg', [
        'width' => 800,
    ]);
})->throws(Exception::class, 'Image dimensions are missing and could not be auto-detected.');



/**
 * TODO:
 * - properly translates Craft transform params
 * - completes auto-detection without imagick present
 * - honors default quality
 */
