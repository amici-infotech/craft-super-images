<?php
/**
 * GD extension image driver for basic transforms and native encoding.
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

/**
 * GD Driver
 *
 * Image processing backend using PHP's bundled GD extension.
 * Suitable as a widely available fallback when Imagick or Libvips are not installed.
 */
final class GdDriver extends AbstractDriver
{
    /**
     * Returns the driver identifier used in configuration and logging.
     *
     * @return string Always "gd".
     */
    public function name(): string
    {
        return 'gd';
    }

    /**
     * Checks whether the GD extension is loaded.
     *
     * @return bool True when the `gd` PHP extension is available.
     */
    public function isAvailable(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * Loads a source image from disk or bytes into a GD-backed handle.
     *
     * @param SourceImage $source File path, raw bytes, and optional MIME metadata.
     *
     * @return ImageHandle In-memory GD image with dimensions and alpha metadata.
     *
     * @throws ProcessingException When the source cannot be decoded.
     */
    public function load(SourceImage $source): ImageHandle
    {
        $resource = $this->createFromSource($source);
        $width = imagesx($resource);
        $height = imagesy($resource);
        $mime = $source->mime ?? $this->detectMime($resource);

        return new ImageHandle(
            $this->name(),
            $resource,
            $width,
            $height,
            $this->hasAlpha($resource),
            $mime,
        );
    }

    /**
     * Returns the current width and height of a loaded handle.
     *
     * @param ImageHandle $handle The GD-backed image handle.
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
     * Describes supported operations, output formats, and feature flags for GD.
     *
     * @return DriverCapabilities Capability metadata for pipeline routing.
     */
    public function capabilities(): DriverCapabilities
    {
        $formats = ['jpeg', 'jpg', 'png', 'webp'];
        if (function_exists('imageavif')) {
            $formats[] = 'avif';
        }

        return new DriverCapabilities(
            operations: [
                'resize', 'crop', 'fit', 'fill', 'scale', 'rotate', 'flip',
                'brightness', 'contrast', 'grayscale', 'sharpen', 'blur',
                'background', 'padding', 'border', 'invert',
            ],
            formats: $formats,
            supportsAlpha: true,
            supportsWatermark: false,
        );
    }

    /**
     * Encodes the handle to the requested format using GD's native writers.
     *
     * @param ImageHandle $handle The GD-backed image to encode.
     * @param string $format Target format slug (jpeg, png, webp, avif).
     * @param EncodeOptions $options Quality and other encode settings.
     *
     * @return EncodedImage Raw bytes and metadata for the encoded image.
     *
     * @throws UnsupportedFormatException When AVIF or another format is unavailable in GD.
     * @throws ProcessingException When encoding fails.
     */
    public function encodeNative(ImageHandle $handle, string $format, EncodeOptions $options): EncodedImage
    {
        $format = $this->normalizeFormat($format);
        ob_start();

        $success = match ($format) {
            'jpeg', 'jpg' => imagejpeg($handle->resource, null, $options->qualityOrDefault(82)),
            'png' => imagepng($handle->resource, null, 6),
            'webp' => imagewebp($handle->resource, null, $options->qualityOrDefault(80)),
            'avif' => function_exists('imageavif')
                ? imageavif($handle->resource, null, $options->qualityOrDefault(65))
                : throw new UnsupportedFormatException('AVIF encoding is not available in GD.'),
            default => throw new UnsupportedFormatException(sprintf('Format "%s" is not supported by GD.', $format)),
        };

        if (!$success) {
            ob_end_clean();
            throw new ProcessingException('GD failed to encode image.');
        }

        $bytes = ob_get_clean() ?: '';
        $mime = $this->formatMime($format);

        return new EncodedImage(
            $format,
            $handle->width,
            $handle->height,
            strlen($bytes),
            $mime,
            $bytes,
        );
    }

    /**
     * Releases GD image memory held by the handle resource.
     *
     * @param ImageHandle $handle The handle whose GD resource should be destroyed.
     *
     * @return void
     */
    public function destroy(ImageHandle $handle): void
    {
        if (is_resource($handle->resource) || $handle->resource instanceof \GdImage) {
            imagedestroy($handle->resource);
        }
    }

    /**
     * Resizes the image to explicit or derived target dimensions.
     *
     * @param ImageHandle $handle Source GD handle.
     * @param int|null $width Target width in pixels, or null to derive from height.
     * @param int|null $height Target height in pixels, or null to derive from width.
     * @param string $mode Dimension resolution mode: "fit" preserves aspect ratio constraints; "scale" stretches to exact size.
     *
     * @return ImageHandle Resampled GD handle at the target size.
     */
    public function resize(ImageHandle $handle, ?int $width, ?int $height, string $mode = 'fit'): ImageHandle
    {
        [$targetWidth, $targetHeight] = $this->resolveTargetDimensions($handle, $width, $height, $mode);
        $dest = imagecreatetruecolor($targetWidth, $targetHeight);
        $this->preserveAlpha($dest);
        imagecopyresampled(
            $dest,
            $handle->resource,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $handle->width,
            $handle->height,
        );

        return new ImageHandle($this->name(), $dest, $targetWidth, $targetHeight, $handle->hasAlpha, $handle->mime);
    }

    /**
     * Crops and resamples the image to exact dimensions using a focal position.
     *
     * @param ImageHandle $handle Source GD handle.
     * @param int $width Output width in pixels.
     * @param int $height Output height in pixels.
     * @param string $position Crop anchor in the form "xAlign-yAlign" (e.g. "center-center").
     *
     * @return ImageHandle Cropped GD handle at the requested size.
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

        $dest = imagecreatetruecolor($width, $height);
        $this->preserveAlpha($dest);
        imagecopyresampled(
            $dest,
            $handle->resource,
            0,
            0,
            $srcX,
            $srcY,
            $width,
            $height,
            $cropWidth,
            $cropHeight,
        );

        return new ImageHandle($this->name(), $dest, $width, $height, $handle->hasAlpha, $handle->mime);
    }

    /**
     * Scales the image down to fit within optional max bounds while preserving aspect ratio.
     *
     * @param ImageHandle $handle Source GD handle.
     * @param int|null $width Maximum output width, or null for height-only constraint.
     * @param int|null $height Maximum output height, or null for width-only constraint.
     *
     * @return ImageHandle Resized GD handle that fits within the bounds.
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
     * @param ImageHandle $handle Source GD handle.
     * @param int $width Output width in pixels.
     * @param int $height Output height in pixels.
     * @param string $position Crop anchor in the form "xAlign-yAlign".
     *
     * @return ImageHandle Cropped GD handle at the requested size.
     */
    public function fill(ImageHandle $handle, int $width, int $height, string $position = 'center-center'): ImageHandle
    {
        return $this->crop($handle, $width, $height, $position);
    }

    /**
     * Uniformly scales the image by a multiplicative factor.
     *
     * @param ImageHandle $handle Source GD handle.
     * @param float $factor Scale multiplier (e.g. 0.5 halves each dimension).
     *
     * @return ImageHandle Scaled GD handle.
     */
    public function scale(ImageHandle $handle, float $factor): ImageHandle
    {
        $width = max(1, (int)round($handle->width * $factor));
        $height = max(1, (int)round($handle->height * $factor));

        return $this->resize($handle, $width, $height, 'scale');
    }

    /**
     * Rotates the image by the given angle in degrees.
     *
     * @param ImageHandle $handle Source GD handle.
     * @param float $angle Clockwise rotation angle in degrees.
     * @param int $background Fill color for exposed areas after rotation (GD color index).
     *
     * @return ImageHandle Rotated GD handle with updated dimensions.
     *
     * @throws ProcessingException When rotation fails.
     */
    public function rotate(ImageHandle $handle, float $angle, int $background = 0): ImageHandle
    {
        $resource = imagerotate($handle->resource, -$angle, $background);
        if ($resource === false) {
            throw new ProcessingException('GD failed to rotate image.');
        }

        $width = imagesx($resource);
        $height = imagesy($resource);

        return new ImageHandle($this->name(), $resource, $width, $height, $handle->hasAlpha, $handle->mime);
    }

    /**
     * Flips the image horizontally or vertically.
     *
     * @param ImageHandle $handle Source GD handle.
     * @param string $direction "horizontal" or "vertical".
     *
     * @return ImageHandle Flipped GD handle.
     */
    public function flip(ImageHandle $handle, string $direction = 'horizontal'): ImageHandle
    {
        $mode = $direction === 'vertical' ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL;
        $resource = $this->cloneImage($handle->resource);
        imageflip($resource, $mode);

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, $handle->hasAlpha, $handle->mime);
    }

    /**
     * Adjusts image brightness by adding the level to each RGB channel.
     *
     * @param ImageHandle $handle Source GD handle.
     * @param int $level Brightness delta, typically between -255 and 255.
     *
     * @return ImageHandle Adjusted GD handle.
     */
    public function brightness(ImageHandle $handle, int $level): ImageHandle
    {
        $resource = $this->cloneImage($handle->resource);
        imagefilter($resource, IMG_FILTER_BRIGHTNESS, $level);

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, $handle->hasAlpha, $handle->mime);
    }

    /**
     * Adjusts image contrast.
     *
     * @param ImageHandle $handle Source GD handle.
     * @param int $level Contrast level accepted by GD's contrast filter.
     *
     * @return ImageHandle Adjusted GD handle.
     */
    public function contrast(ImageHandle $handle, int $level): ImageHandle
    {
        $resource = $this->cloneImage($handle->resource);
        imagefilter($resource, IMG_FILTER_CONTRAST, $level);

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, $handle->hasAlpha, $handle->mime);
    }

    /**
     * Converts the image to grayscale.
     *
     * @param ImageHandle $handle Source GD handle.
     *
     * @return ImageHandle Grayscale GD handle (alpha flag cleared).
     */
    public function grayscale(ImageHandle $handle): ImageHandle
    {
        $resource = $this->cloneImage($handle->resource);
        imagefilter($resource, IMG_FILTER_GRAYSCALE);

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, false, $handle->mime);
    }

    /**
     * Inverts all colors in the image.
     *
     * @param ImageHandle $handle Source GD handle.
     *
     * @return ImageHandle Inverted GD handle.
     */
    public function invert(ImageHandle $handle): ImageHandle
    {
        $resource = $this->cloneImage($handle->resource);
        imagefilter($resource, IMG_FILTER_NEGATE);

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, $handle->hasAlpha, $handle->mime);
    }

    /**
     * Applies a convolution-based sharpen filter.
     *
     * @param ImageHandle $handle Source GD handle.
     * @param float $amount Sharpen strength; higher values increase edge emphasis.
     *
     * @return ImageHandle Sharpened GD handle.
     */
    public function sharpen(ImageHandle $handle, float $amount = 1.0): ImageHandle
    {
        $resource = $this->cloneImage($handle->resource);
        $matrix = [
            [-1, -1, -1],
            [-1, 16, -1],
            [-1, -1, -1],
        ];
        $divisor = max(1, (int)(16 - ($amount * 4)));
        imageconvolution($resource, $matrix, $divisor, 0);

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, $handle->hasAlpha, $handle->mime);
    }

    /**
     * Applies a Gaussian blur filter one or more times.
     *
     * @param ImageHandle $handle Source GD handle.
     * @param int $passes Number of blur passes to apply.
     *
     * @return ImageHandle Blurred GD handle.
     */
    public function blur(ImageHandle $handle, int $passes = 1): ImageHandle
    {
        $resource = $this->cloneImage($handle->resource);
        for ($i = 0; $i < max(1, $passes); $i++) {
            imagefilter($resource, IMG_FILTER_GAUSSIAN_BLUR);
        }

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, $handle->hasAlpha, $handle->mime);
    }

    /**
     * Composites the image over a solid background color canvas.
     *
     * @param ImageHandle $handle Source GD handle.
     * @param string $color Hex color string (e.g. "#ffffff").
     *
     * @return ImageHandle GD handle with the background applied behind the image.
     */
    public function background(ImageHandle $handle, string $color): ImageHandle
    {
        $resource = imagecreatetruecolor($handle->width, $handle->height);
        $bg = $this->parseColor($resource, $color);
        imagefilledrectangle($resource, 0, 0, $handle->width, $handle->height, $bg);
        imagecopy($resource, $handle->resource, 0, 0, 0, 0, $handle->width, $handle->height);

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, $handle->hasAlpha, $handle->mime);
    }

    /**
     * Adds padding around the image using a solid border color.
     *
     * @param ImageHandle $handle Source GD handle.
     * @param int $top Top padding in pixels.
     * @param int $right Right padding in pixels.
     * @param int $bottom Bottom padding in pixels.
     * @param int $left Left padding in pixels.
     * @param string $color Hex color string for the padded area.
     *
     * @return ImageHandle GD handle with expanded canvas and centered original image.
     */
    public function padding(ImageHandle $handle, int $top, int $right, int $bottom, int $left, string $color = '#ffffff'): ImageHandle
    {
        $width = $handle->width + $left + $right;
        $height = $handle->height + $top + $bottom;
        $resource = imagecreatetruecolor($width, $height);
        $this->preserveAlpha($resource);
        $bg = $this->parseColor($resource, $color);
        imagefilledrectangle($resource, 0, 0, $width, $height, $bg);
        imagecopy($resource, $handle->resource, $left, $top, 0, 0, $handle->width, $handle->height);

        return new ImageHandle($this->name(), $resource, $width, $height, $handle->hasAlpha, $handle->mime);
    }

    /**
     * Adds a uniform border by delegating to {@see padding()} with equal insets.
     *
     * @param ImageHandle $handle Source GD handle.
     * @param int $size Border thickness in pixels on all sides.
     * @param string $color Hex color string for the border.
     *
     * @return ImageHandle GD handle with a colored border.
     */
    public function border(ImageHandle $handle, int $size, string $color = '#000000'): ImageHandle
    {
        return $this->padding($handle, $size, $size, $size, $size, $color);
    }

    /**
     * Creates a GD image resource from a {@see SourceImage}.
     *
     * @param SourceImage $source File path or raw bytes.
     *
     * @return \GdImage Decoded GD image resource.
     *
     * @throws ProcessingException When decoding fails.
     */
    private function createFromSource(SourceImage $source): \GdImage
    {
        if ($source->path !== null && is_readable($source->path)) {
            $resource = @imagecreatefromstring((string)file_get_contents($source->path));
        } elseif ($source->bytes !== null) {
            $resource = @imagecreatefromstring($source->bytes);
        } else {
            throw new ProcessingException('GD cannot load source image.');
        }

        if ($resource === false) {
            throw new ProcessingException('GD failed to load source image.');
        }

        return $resource;
    }

    /**
     * Duplicates a GD image resource with alpha preservation.
     *
     * @param \GdImage|resource $resource Source GD image.
     *
     * @return \GdImage Cloned GD image.
     */
    private function cloneImage($resource): \GdImage
    {
        $width = imagesx($resource);
        $height = imagesy($resource);
        $clone = imagecreatetruecolor($width, $height);
        $this->preserveAlpha($clone);
        imagecopy($clone, $resource, 0, 0, 0, 0, $width, $height);

        return $clone;
    }

    /**
     * Configures a GD resource for correct alpha channel handling.
     *
     * @param \GdImage|resource $resource GD image to configure.
     *
     * @return void
     */
    private function preserveAlpha($resource): void
    {
        imagealphablending($resource, false);
        imagesavealpha($resource, true);
    }

    /**
     * Heuristically detects whether the image may carry an alpha channel.
     *
     * @param \GdImage|resource $resource GD image to inspect.
     *
     * @return bool True when alpha blending support is available and the image has non-zero width.
     */
    private function hasAlpha($resource): bool
    {
        return (imagesx($resource) > 0) && function_exists('imagealphablending');
    }

    /**
     * Returns a fallback MIME type when the source did not provide one.
     *
     * @param \GdImage|resource $resource GD image (unused; GD does not expose MIME reliably).
     *
     * @return string Default MIME type string.
     */
    private function detectMime($resource): string
    {
        return 'image/png';
    }

    /**
     * Resolves explicit or derived target dimensions for resize operations.
     *
     * @param ImageHandle $handle Source handle supplying current dimensions.
     * @param int|null $width Requested width, or null to derive.
     * @param int|null $height Requested height, or null to derive.
     * @param string $mode "fit" or "scale" dimension resolution mode.
     *
     * @return array{0: int, 1: int} Tuple of [width, height], each at least 1.
     */
    private function resolveTargetDimensions(ImageHandle $handle, ?int $width, ?int $height, string $mode): array
    {
        if ($width === null && $height === null) {
            return [$handle->width, $handle->height];
        }

        if ($mode === 'scale' && $width !== null && $height === null) {
            $ratio = $width / max(1, $handle->width);

            return [max(1, $width), max(1, (int)round($handle->height * $ratio))];
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
     * Allocates a GD color index from a hex color string.
     *
     * @param \GdImage|resource $resource GD image used for color allocation.
     * @param string $color Hex color with or without leading `#`.
     *
     * @return int GD color index; defaults to white when parsing fails.
     */
    private function parseColor($resource, string $color): int
    {
        $color = ltrim(trim($color), '#');

        if (strlen($color) === 6) {
            $r = hexdec(substr($color, 0, 2));
            $g = hexdec(substr($color, 2, 2));
            $b = hexdec(substr($color, 4, 2));

            return imagecolorallocate($resource, $r, $g, $b);
        }

        return imagecolorallocate($resource, 255, 255, 255);
    }

    /**
     * Normalizes format slugs for GD encode functions.
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
