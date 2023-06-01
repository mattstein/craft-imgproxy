<?php

use modules\imgproxy\Module;

it('throws exception', function() {
    Module::getBuilder();
})->throws(Exception::class, 'An imgproxy instance URL is required.');

/**
 * TODO:
 * - properly translates Craft transform params
 * - completes auto-detection without imagick present
 * - honors default quality
 * - auto-detects expected image types
 * - throws exception for non-auto-detectable image
 */