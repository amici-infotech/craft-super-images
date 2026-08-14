<?php
/**
 * Libvips image driver for high-performance transforms and native encoding.
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
use Jcupitt\Vips\Image;

/**
 * Libvips Driver
 *
 * Image processing backend using the libvips PHP binding (`Jcupitt\Vips\Image`).
 * Favors lazy, streaming evaluation for memory-efficient batch derivative generation.
 */
final class LibvipsDriver extends AbstractDriver
{
    /**
     * Returns the driver identifier used in configuration and logging.
     *
     * @return string Always "libvips".
     */
    public function name(): string
    {
        return 'libvips';
    }

    /**
     * Checks whether the libvips PHP binding is installed.
     *
     * @return bool True when {@see Image} class exists.
     */
    public function isAvailable(): bool
    {
        return class_exists(Image::class);
    }

    /**
     * Loads a source image from disk or bytes into a libvips-backed handle.
     *
     * @param SourceImage $source File path, raw bytes, and optional MIME metadata.
     *
     * @return ImageHandle In-memory libvips image with dimensions and alpha metadata.
     *
     * @throws ProcessingException When the source cannot be read.
     */
    public function load(SourceImage $source): ImageHandle
    {
        if ($source->path !== null && is_readable($source->path)) {
            $image = Image::newFromFile($source->path, ['access' => 'sequential']);
        } elseif ($source->bytes !== null) {
            $image = Image::newFromBuffer($source->bytes, '', ['access' => 'sequential']);
        } else {
            throw new ProcessingException('Libvips cannot load source image.');
        }

        return $this->handleFromImage($image, $source->mime ?? 'image/jpeg');
    }

    /**
     * Returns the current width and height of a loaded handle.
     *
     * @param ImageHandle $handle The libvips-backed image handle.
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
     * @param string $operation Operation slug (e.g. "resize", "crop", "grayscale").
     *
     * @return bool True when the operation appears in {@see capabilities()}.
     */
    public function supports(string $operation): bool
    {
        return in_array($operation, $this->capabilities()->operations, true);
    }

    /**
     * Describes supported operations, output formats, and feature flags for libvips.
     *
     * @return DriverCapabilities Capability metadata for pipeline routing.
     */
    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            operations: [
                'resize', 'crop', 'fit', 'fill', 'scale', 'rotate', 'flip',
                'brightness', 'contrast', 'grayscale', 'sharpen', 'blur',
            ],
            formats: ['jpeg', 'jpg', 'png', 'webp', 'avif'],
            supportsAlpha: true,
            supportsWatermark: false,
        );
    }

    /**
     * Encodes the handle to the requested format using libvips buffer writers.
     *
     * @param ImageHandle $handle The libvips-backed image to encode.
     * @param string $format Target format slug (jpeg, png, webp, avif).
     * @param EncodeOptions $options Quality and other encode settings mapped to libvips save options.
     *
     * @return EncodedImage Raw bytes and metadata for the encoded image.
     *
     * @throws UnsupportedFormatException When the format is not supported by libvips.
     */
    public function encodeNative(ImageHandle $handle, string $format, EncodeOptions $options): EncodedImage
    {
        /** @var Image $image */
        $image = $handle->resource;
        $format = $this->normalizeFormat($format);

        $saveOptions = match ($format) {
            'jpeg', 'jpg' => ['Q' => $options->qualityOrDefault(82)],
            'webp' => ['Q' => $options->qualityOrDefault(80)],
            'avif' => ['Q' => $options->qualityOrDefault(65)],
            'png' => [],
            default => throw new UnsupportedFormatException(sprintf('Format "%s" is not supported by Libvips.', $format)),
        };

        $bytes = $image->writeToBuffer('.' . $format, $saveOptions);

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
     * Releases libvips resources — a no-op because libvips objects are garbage-collected.
     *
     * @param ImageHandle $handle The handle whose resource may be released by PHP GC.
     *
     * @return void
     */
    public function destroy(ImageHandle $handle): void
    {
        // Libvips Image objects are garbage-collected.
    }

    /**
     * Resizes the image to explicit or derived target dimensions.
     *
     * @param ImageHandle $handle Source libvips handle.
     * @param int|null $width Target width in pixels, or null to derive from height.
     * @param int|null $height Target height in pixels, or null to derive from width.
     * @param string $mode Dimension resolution mode passed to {@see resolveTargetDimensions()}.
     *
     * @return ImageHandle Resized libvips handle.
     */
    public function resize(ImageHandle $handle, ?int $width, ?int $height, string $mode = 'fit'): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;
        [$targetWidth, $targetHeight] = $this->resolveTargetDimensions($handle, $width, $height, $mode);
        $resized = $image->resize($targetWidth / $handle->width, ['vscale' => $targetHeight / $handle->height]);

        return $this->handleFromImage($resized, $handle->mime);
    }

    /**
     * Crops to an aspect-correct region then resamples to exact output dimensions.
     *
     * @param ImageHandle $handle Source libvips handle.
     * @param int $width Output width in pixels.
     * @param int $height Output height in pixels.
     * @param string $position Crop anchor in the form "xAlign-yAlign".
     *
     * @return ImageHandle Cropped and resized libvips handle.
     */
    public function crop(ImageHandle $handle, int $width, int $height, string $position = 'center-center'): ImageHandle
    {
        [$srcX, $srcY, $cropWidth, $cropHeight] = $this->calculateCropBox(
            $handle->width,
            $handle->height,
            $width,
            $height,
            $position,
        );

        /** @var Image $image */
        $image = $handle->resource;
        $cropped = $image->crop($srcX, $srcY, $cropWidth, $cropHeight)->resize(
            $width / $cropWidth,
            ['vscale' => $height / $cropHeight],
        );

        return $this->handleFromImage($cropped, $handle->mime);
    }

    /**
     * Scales the image down to fit within optional max bounds while preserving aspect ratio.
     *
     * @param ImageHandle $handle Source libvips handle.
     * @param int|null $width Maximum output width, or null for height-only constraint.
     * @param int|null $height Maximum output height, or null for width-only constraint.
     *
     * @return ImageHandle Resized libvips handle that fits within the bounds.
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
     * @param ImageHandle $handle Source libvips handle.
     * @param int $width Output width in pixels.
     * @param int $height Output height in pixels.
     * @param string $position Crop anchor in the form "xAlign-yAlign".
     *
     * @return ImageHandle Cropped libvips handle at the requested size.
     */
    public function fill(ImageHandle $handle, int $width, int $height, string $position = 'center-center'): ImageHandle
    {
        return $this->crop($handle, $width, $height, $position);
    }

    /**
     * Uniformly scales the image by a multiplicative factor on both axes.
     *
     * @param ImageHandle $handle Source libvips handle.
     * @param float $factor Scale multiplier applied horizontally and vertically.
     *
     * @return ImageHandle Scaled libvips handle.
     */
    public function scale(ImageHandle $handle, float $factor): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;
        $scaled = $image->resize($factor, ['vscale' => $factor]);

        return $this->handleFromImage($scaled, $handle->mime);
    }

    /**
     * Rotates the image by the given angle in degrees.
     *
     * @param ImageHandle $handle Source libvips handle.
     * @param float $angle Rotation angle in degrees.
     *
     * @return ImageHandle Rotated libvips handle with updated dimensions.
     */
    public function rotate(ImageHandle $handle, float $angle): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;
        $rotated = $image->rotate($angle);

        return $this->handleFromImage($rotated, $handle->mime);
    }

    /**
     * Flips the image horizontally or vertically.
     *
     * @param ImageHandle $handle Source libvips handle.
     * @param string $direction "horizontal" or "vertical".
     *
     * @return ImageHandle Flipped libvips handle.
     */
    public function flip(ImageHandle $handle, string $direction = 'horizontal'): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;
        $flipped = $direction === 'vertical' ? $image->flipvertical() : $image->fliphoriz();

        return $this->handleFromImage($flipped, $handle->mime);
    }

    /**
     * Adjusts image brightness by applying a linear offset to each RGB band.
     *
     * @param ImageHandle $handle Source libvips handle.
     * @param float $amount Brightness offset added to each channel.
     *
     * @return ImageHandle Brightness-adjusted libvips handle.
     */
    public function brightness(ImageHandle $handle, float $amount): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;

        return $this->handleFromImage($image->linear([1, 1, 1], [$amount, $amount, $amount]), $handle->mime);
    }

    /**
     * Adjusts image contrast by scaling RGB bands around zero.
     *
     * @param ImageHandle $handle Source libvips handle.
     * @param float $amount Contrast percentage; 0 is neutral, positive values increase contrast.
     *
     * @return ImageHandle Contrast-adjusted libvips handle.
     */
    public function contrast(ImageHandle $handle, float $amount): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;
        $factor = 1 + ($amount / 100);

        return $this->handleFromImage($image->linear([$factor, $factor, $factor], [0, 0, 0]), $handle->mime);
    }

    /**
     * Converts the image to grayscale using libvips colourspace conversion.
     *
     * @param ImageHandle $handle Source libvips handle.
     *
     * @return ImageHandle Grayscale libvips handle.
     */
    public function grayscale(ImageHandle $handle): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;

        return $this->handleFromImage($image->colourspace('b-w'), $handle->mime);
    }

    /**
     * Sharpens the image using libvips' sharpen operation.
     *
     * @param ImageHandle $handle Source libvips handle.
     * @param float $amount Sigma parameter controlling sharpen strength.
     *
     * @return ImageHandle Sharpened libvips handle.
     */
    public function sharpen(ImageHandle $handle, float $amount = 1.0): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;

        return $this->handleFromImage($image->sharpen(['sigma' => $amount]), $handle->mime);
    }

    /**
     * Applies a Gaussian blur to the image.
     *
     * @param ImageHandle $handle Source libvips handle.
     * @param float $sigma Blur sigma passed to libvips `gaussblur`.
     *
     * @return ImageHandle Blurred libvips handle.
     */
    public function blur(ImageHandle $handle, float $sigma = 1.0): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;

        return $this->handleFromImage($image->gaussblur($sigma), $handle->mime);
    }

    /**
     * Wraps a libvips {@see Image} in a plugin {@see ImageHandle}.
     *
     * @param Image $image Loaded or transformed libvips image.
     * @param string $mime MIME type to store on the handle.
     *
     * @return ImageHandle Handle with dimensions and alpha metadata (4 bands implies alpha).
     */
    private function handleFromImage(Image $image, string $mime): ImageHandle
    {
        return new ImageHandle(
            $this->name(),
            $image,
            $image->width,
            $image->height,
            $image->bands === 4,
            $mime,
        );
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
     * Normalizes format slugs for libvips buffer writers.
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
     */
    private function formatMime(string $format): string
    {
        return match ($format) {
            'jpeg', 'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            default => 'application/octet-stream',
        };
    }
}
