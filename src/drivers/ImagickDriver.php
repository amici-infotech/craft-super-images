<?php
/**
 * Imagick extension image driver for rich transforms, overlays, and native encoding.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\drivers;

use amici\SuperImages\exceptions\ProcessingException;
use amici\SuperImages\exceptions\UnsupportedFormatException;
use amici\SuperImages\models\Dimensions;
use amici\SuperImages\models\DriverCapabilities;
use amici\SuperImages\models\EncodeOptions;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\models\SourceImage;
use Imagick;

/**
 * Imagick Driver
 *
 * Image processing backend using the ImageMagick PHP extension.
 * Supports the broadest set of operations including watermarks, overlays, and sepia.
 */
final class ImagickDriver extends AbstractDriver
{
    /**
     * Returns the driver identifier used in configuration and logging.
     *
     * @return string Always "imagick".
     */
    public function name(): string
    {
        return 'imagick';
    }

    /**
     * Checks whether the Imagick extension and class are available.
     *
     * @return bool True when `imagick` is loaded and {@see Imagick} exists.
     */
    public function isAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class);
    }

    /**
     * Loads a source image from disk or bytes into an Imagick-backed handle.
     *
     * @param SourceImage $source File path, raw bytes, and optional MIME metadata.
     *
     * @return ImageHandle In-memory Imagick image with dimensions and alpha metadata.
     *
     * @throws ProcessingException When the source cannot be read.
     */
    public function load(SourceImage $source): ImageHandle
    {
        $imagick = new Imagick();

        if ($source->path !== null && is_readable($source->path)) {
            $imagick->readImage($source->path);
        } elseif ($source->bytes !== null) {
            $imagick->readImageBlob($source->bytes);
        } else {
            throw new ProcessingException('Imagick cannot load source image.');
        }

        $imagick->setIteratorIndex(0);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Returns the current width and height of a loaded handle.
     *
     * @param ImageHandle $handle The Imagick-backed image handle.
     *
     * @return Dimensions Pixel dimensions of the handle.
     */
    public function dimensions(ImageHandle $handle): Dimensions
    {
        return new Dimensions($handle->width, $handle->height);
    }

    /**
     * Checks whether this driver implements the named operation.
     *
     * @param string $operation Operation slug (e.g. "resize", "watermark", "sepia").
     *
     * @return bool True when the operation appears in {@see capabilities()}.
     */
    public function supports(string $operation): bool
    {
        return in_array($operation, $this->capabilities()->operations, true);
    }

    /**
     * Describes supported operations, output formats, and feature flags for Imagick.
     *
     * @return DriverCapabilities Capability metadata for pipeline routing.
     */
    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            operations: [
                'resize', 'crop', 'fit', 'fill', 'scale', 'rotate', 'flip',
                'brightness', 'contrast', 'saturation', 'grayscale', 'sepia', 'invert',
                'sharpen', 'blur', 'background', 'padding', 'border', 'watermark', 'overlay',
            ],
            formats: ['jpeg', 'jpg', 'png', 'webp', 'avif'],
            supportsAlpha: true,
            supportsWatermark: true,
        );
    }

    /**
     * Encodes the handle to the requested format using Imagick's native writers.
     *
     * @param ImageHandle $handle The Imagick-backed image to encode.
     * @param string $format Target format slug (jpeg, png, webp, avif).
     * @param EncodeOptions $options Quality, metadata stripping, and other encode settings.
     *
     * @return EncodedImage Raw bytes and metadata for the encoded image.
     */
    public function encodeNative(ImageHandle $handle, string $format, EncodeOptions $options): EncodedImage
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $format = $this->normalizeFormat($format);
        $imagick->setImageFormat($format);

        if (in_array($format, ['jpeg', 'jpg', 'webp', 'avif'], true)) {
            $imagick->setImageCompressionQuality($options->qualityOrDefault(82));
        }

        if ($options->stripMetadata) {
            $imagick->stripImage();
        }

        $bytes = $imagick->getImagesBlob();

        return new EncodedImage(
            $format,
            $handle->width,
            $handle->height,
            strlen($bytes),
            $this->formatMime($format),
            $bytes,
        );
    }

    /**
     * Clears and destroys the Imagick resource held by the handle.
     *
     * @param ImageHandle $handle The handle whose Imagick instance should be released.
     *
     * @return void
     */
    public function destroy(ImageHandle $handle): void
    {
        if ($handle->resource instanceof Imagick) {
            $handle->resource->clear();
            $handle->resource->destroy();
        }
    }

    /**
     * Resizes the image to explicit or derived target dimensions using Lanczos filtering.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param int|null $width Target width in pixels, or null to derive from height.
     * @param int|null $height Target height in pixels, or null to derive from width.
     * @param string $mode Dimension resolution mode passed to {@see resolveTargetDimensions()}.
     *
     * @return ImageHandle Resized Imagick handle.
     */
    public function resize(ImageHandle $handle, ?int $width, ?int $height, string $mode = 'fit'): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        [$targetWidth, $targetHeight] = $this->resolveTargetDimensions($handle, $width, $height, $mode);
        $imagick->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Crops to an aspect-correct region then resamples to exact output dimensions.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param int $width Output width in pixels.
     * @param int $height Output height in pixels.
     * @param string $position Crop anchor in the form "xAlign-yAlign".
     *
     * @return ImageHandle Cropped and resized Imagick handle.
     */
    public function crop(ImageHandle $handle, int $width, int $height, string $position = 'center-center'): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        [$srcX, $srcY, $cropWidth, $cropHeight] = $this->calculateCropBox(
            $handle->width,
            $handle->height,
            $width,
            $height,
            $position,
        );
        $imagick->cropImage($cropWidth, $cropHeight, $srcX, $srcY);
        $imagick->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Scales the image down to fit within optional max bounds while preserving aspect ratio.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param int|null $width Maximum output width, or null for height-only constraint.
     * @param int|null $height Maximum output height, or null for width-only constraint.
     *
     * @return ImageHandle Resized Imagick handle that fits within the bounds.
     */
    public function fit(ImageHandle $handle, ?int $width, ?int $height): ImageHandle
    {
        [$targetWidth, $targetHeight] = $this->calculateFitDimensions(
            $handle->width,
            $handle->height,
            $width,
            $height,
        );

        return $this->resize($handle, $targetWidth, $targetHeight, 'fit');
    }

    /**
     * Alias for {@see crop()} — fills the target box by cropping excess area.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param int $width Output width in pixels.
     * @param int $height Output height in pixels.
     * @param string $position Crop anchor in the form "xAlign-yAlign".
     *
     * @return ImageHandle Cropped Imagick handle at the requested size.
     */
    public function fill(ImageHandle $handle, int $width, int $height, string $position = 'center-center'): ImageHandle
    {
        return $this->crop($handle, $width, $height, $position);
    }

    /**
     * Uniformly scales the image by a multiplicative factor.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param float $factor Scale multiplier applied to both dimensions.
     *
     * @return ImageHandle Scaled Imagick handle.
     */
    public function scale(ImageHandle $handle, float $factor): ImageHandle
    {
        $width = max(1, (int)round($handle->width * $factor));
        $height = max(1, (int)round($handle->height * $factor));

        return $this->resize($handle, $width, $height, 'scale');
    }

    /**
     * Rotates the image by the given angle with an optional background fill color.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param float $angle Rotation angle in degrees.
     * @param string $background Imagick pixel color string for exposed areas (e.g. "transparent").
     *
     * @return ImageHandle Rotated Imagick handle with updated dimensions.
     */
    public function rotate(ImageHandle $handle, float $angle, string $background = 'transparent'): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->rotateImage(new \ImagickPixel($background), $angle);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Flips the image horizontally (flop) or vertically (flip).
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param string $direction "horizontal" or "vertical".
     *
     * @return ImageHandle Flipped Imagick handle.
     */
    public function flip(ImageHandle $handle, string $direction = 'horizontal'): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;

        if ($direction === 'vertical') {
            $imagick->flipImage();
        } else {
            $imagick->flopImage();
        }

        return $this->handleFromImagick($imagick);
    }

    /**
     * Adjusts image brightness via Imagick's modulate filter.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param int $level Brightness delta added to the 100% baseline.
     *
     * @return ImageHandle Brightness-adjusted Imagick handle.
     */
    public function brightness(ImageHandle $handle, int $level): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->modulateImage(100 + $level, 100, 100);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Adjusts image contrast using a sigmoidal contrast curve.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param int $level Positive values increase contrast; magnitude controls strength.
     *
     * @return ImageHandle Contrast-adjusted Imagick handle.
     */
    public function contrast(ImageHandle $handle, int $level): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->sigmoidalContrastImage($level > 0, abs($level) / 10, 0);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Adjusts color saturation via Imagick's modulate filter.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param int $level Saturation delta added to the 100% baseline.
     *
     * @return ImageHandle Saturation-adjusted Imagick handle.
     */
    public function saturation(ImageHandle $handle, int $level): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->modulateImage(100, 100 + $level, 100);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Converts the image to grayscale by zeroing saturation.
     *
     * @param ImageHandle $handle Source Imagick handle.
     *
     * @return ImageHandle Grayscale Imagick handle.
     */
    public function grayscale(ImageHandle $handle): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->modulateImage(100, 0, 100);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Applies a sepia tone effect at the given threshold.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param int $threshold Sepia intensity from 0–100 mapped to Imagick's quantum range.
     *
     * @return ImageHandle Sepia-toned Imagick handle.
     */
    public function sepia(ImageHandle $handle, int $threshold = 80): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->sepiaToneImage($threshold * Imagick::getQuantumRange()['quantumRangeLong'] / 100);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Inverts all colors in the image.
     *
     * @param ImageHandle $handle Source Imagick handle.
     *
     * @return ImageHandle Color-inverted Imagick handle.
     */
    public function invert(ImageHandle $handle): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->negateImage(false);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Sharpens the image using Imagick's unsharp mask.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param float $amount Sharpen radius/strength parameter passed to Imagick.
     *
     * @return ImageHandle Sharpened Imagick handle.
     */
    public function sharpen(ImageHandle $handle, float $amount = 1.0): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->sharpenImage($amount, 1);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Applies a Gaussian blur to the image.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param float $radius Blur radius passed to Imagick.
     * @param float $sigma Blur sigma passed to Imagick.
     *
     * @return ImageHandle Blurred Imagick handle.
     */
    public function blur(ImageHandle $handle, float $radius = 1.0, float $sigma = 1.0): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->blurImage($radius, $sigma);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Composites the image over a solid background color canvas.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param string $color Imagick pixel color string for the canvas.
     *
     * @return ImageHandle Imagick handle with the background applied behind the image.
     */
    public function background(ImageHandle $handle, string $color): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $canvas = new Imagick();
        $canvas->newImage($handle->width, $handle->height, new \ImagickPixel($color));
        $canvas->compositeImage($imagick, Imagick::COMPOSITE_OVER, 0, 0);

        return $this->handleFromImagick($canvas);
    }

    /**
     * Adds padding around the image using a solid border color.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param int $top Top padding in pixels.
     * @param int $right Right padding in pixels.
     * @param int $bottom Bottom padding in pixels.
     * @param int $left Left padding in pixels.
     * @param string $color Imagick pixel color string for the padded area.
     *
     * @return ImageHandle Imagick handle with expanded canvas and centered original image.
     */
    public function padding(ImageHandle $handle, int $top, int $right, int $bottom, int $left, string $color = '#ffffff'): ImageHandle
    {
        $width = $handle->width + $left + $right;
        $height = $handle->height + $top + $bottom;
        $canvas = new Imagick();
        $canvas->newImage($width, $height, new \ImagickPixel($color));
        $canvas->compositeImage($handle->resource, Imagick::COMPOSITE_OVER, $left, $top);

        return $this->handleFromImagick($canvas);
    }

    /**
     * Adds a uniform border by delegating to {@see padding()} with equal insets.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param int $size Border thickness in pixels on all sides.
     * @param string $color Imagick pixel color string for the border.
     *
     * @return ImageHandle Imagick handle with a colored border.
     */
    public function border(ImageHandle $handle, int $size, string $color = '#000000'): ImageHandle
    {
        return $this->padding($handle, $size, $size, $size, $size, $color);
    }

    /**
     * Composites a watermark image at a named position with adjustable opacity.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param string $sourcePath Absolute path to the watermark image file.
     * @param string $position Overlay anchor in the form "xAlign-yAlign".
     * @param float $opacity Alpha multiplier from 0.0 (transparent) to 1.0 (opaque).
     *
     * @return ImageHandle Imagick handle with the watermark composited on top.
     */
    public function watermark(ImageHandle $handle, string $sourcePath, string $position = 'bottom-right', float $opacity = 0.5): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $mark = new Imagick($sourcePath);
        $mark->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity, Imagick::CHANNEL_ALPHA);
        [$x, $y] = $this->overlayPosition($handle, $mark, $position);
        $imagick->compositeImage($mark, Imagick::COMPOSITE_OVER, $x, $y);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Composites an overlay image at explicit coordinates with optional opacity.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param string $sourcePath Absolute path to the overlay image file.
     * @param int $x Horizontal offset in pixels from the top-left of the base image.
     * @param int $y Vertical offset in pixels from the top-left of the base image.
     * @param float $opacity Alpha multiplier from 0.0 to 1.0.
     *
     * @return ImageHandle Imagick handle with the overlay composited on top.
     */
    public function overlay(ImageHandle $handle, string $sourcePath, int $x = 0, int $y = 0, float $opacity = 1.0): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $overlay = new Imagick($sourcePath);
        if ($opacity < 1.0) {
            $overlay->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity, Imagick::CHANNEL_ALPHA);
        }
        $imagick->compositeImage($overlay, Imagick::COMPOSITE_OVER, $x, $y);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Wraps an {@see Imagick} instance in a plugin {@see ImageHandle}.
     *
     * @param Imagick $imagick Loaded or transformed Imagick image.
     *
     * @return ImageHandle Handle with dimensions, alpha flag, and MIME metadata.
     */
    private function handleFromImagick(Imagick $imagick): ImageHandle
    {
        $width = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();
        $mime = $imagick->getImageMimeType() ?: 'image/png';

        return new ImageHandle(
            $this->name(),
            $imagick,
            $width,
            $height,
            $imagick->getImageAlphaChannel() !== Imagick::ALPHACHANNEL_UNDEFINED,
            $mime,
        );
    }

    /**
     * Calculates overlay placement coordinates from a named position string.
     *
     * @param ImageHandle $handle Base image handle.
     * @param Imagick $overlay Overlay image whose size determines alignment offsets.
     * @param string $position Overlay anchor in the form "xAlign-yAlign".
     *
     * @return array{0: int, 1: int} Tuple of [x, y] composite offsets.
     */
    private function overlayPosition(ImageHandle $handle, Imagick $overlay, string $position): array
    {
        $overlayWidth = $overlay->getImageWidth();
        $overlayHeight = $overlay->getImageHeight();
        [$xAlign, $yAlign] = $this->parsePosition($position);

        return [
            $this->alignOffset($handle->width, $overlayWidth, $xAlign),
            $this->alignOffset($handle->height, $overlayHeight, $yAlign),
        ];
    }

    /**
     * Resolves explicit or derived target dimensions for resize operations.
     *
     * @param ImageHandle $handle Source handle supplying current dimensions.
     * @param int|null $width Requested width, or null to derive.
     * @param int|null $height Requested height, or null to derive.
     * @param string $mode Dimension resolution mode (unused beyond signature parity).
     *
     * @return array{0: int, 1: int} Tuple of [width, height], each at least 1.
     */
    private function resolveTargetDimensions(ImageHandle $handle, ?int $width, ?int $height, string $mode): array
    {
        if ($width === null && $height === null) {
            return [$handle->width, $handle->height];
        }

        if ($width !== null && $height === null) {
            $ratio = $width / max(1, $handle->width);

            return [max(1, $width), max(1, (int)round($handle->height * $ratio))];
        }

        if ($height !== null && $width === null) {
            $ratio = $height / max(1, $handle->height);

            return [max(1, (int)round($handle->width * $ratio)), max(1, $height)];
        }

        return [max(1, (int)$width), max(1, (int)$height)];
    }

    /**
     * Normalizes format slugs for Imagick encode functions.
     *
     * @param string $format Input format slug.
     *
     * @return string Normalized slug (`jpg` becomes `jpeg`).
     */
    private function normalizeFormat(string $format): string
    {
        $format = strtolower($format);

        return $format === 'jpg' ? 'jpeg' : $format;
    }

    /**
     * Maps a normalized format slug to its MIME type string.
     *
     * @param string $format Normalized format slug.
     *
     * @return string MIME type for HTTP headers.
     *
     * @throws UnsupportedFormatException When the format is not supported by Imagick.
     */
    private function formatMime(string $format): string
    {
        return match ($format) {
            'jpeg', 'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            default => throw new UnsupportedFormatException(sprintf('Format "%s" is not supported by Imagick.', $format)),
        };
    }
}
