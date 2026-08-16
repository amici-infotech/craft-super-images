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
use ImagickDraw;
use ImagickPixel;

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
                'sharpen', 'blur', 'background', 'padding', 'border', 'watermark', 'overlay', 'text',
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

        if (in_array($format, ['jpeg', 'jpg'], true)) {
            $quality = $options->qualityOrDefault(82);
            $imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
            $imagick->setImageCompressionQuality($quality);
        } elseif (in_array($format, ['webp', 'avif'], true)) {
            $imagick->setImageCompressionQuality($options->qualityOrDefault(82));
        }

        if ($format === 'webp') {
            $quality = $options->qualityOrDefault(82);
            // Prefer libwebp-style settings; method 4 is a solid cwebp default.
            $imagick->setOption('webp:method', (string) ($options->extra['method'] ?? 4));
            $imagick->setOption('webp:alpha-quality', (string) ($options->extra['alphaQuality'] ?? $quality));
            if (!empty($options->extra['lossless'])) {
                $imagick->setOption('webp:lossless', 'true');
            }
        }

        if ($options->stripMetadata) {
            $imagick->stripImage();
        }

        $progressive = (bool)($options->extra['progressive'] ?? false);
        if ($progressive && in_array($format, ['jpeg', 'jpg'], true)) {
            $imagick->setInterlaceScheme(Imagick::INTERLACE_PLANE);
        }

        if ($format === 'png') {
            $compression = max(0, min(9, (int)($options->extra['pngCompression'] ?? 6)));
            $imagick->setOption('png:compression-level', (string)$compression);
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
        // blur < 1 sharpens slightly when downscaling (Imagick docs); tunable via policies.geometry.sharpness.
        $imagick->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, $this->sharpness()->blur);
        $this->sharpenAfterDownscale($imagick, $handle->width, $handle->height, $targetWidth, $targetHeight);

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
        [$width, $height] = $this->limitUpscale($handle->width, $handle->height, $width, $height);
        [$srcX, $srcY, $cropWidth, $cropHeight] = $this->calculateCropBox(
            $handle->width,
            $handle->height,
            $width,
            $height,
            $position,
        );
        $imagick->cropImage($cropWidth, $cropHeight, $srcX, $srcY);
        $imagick->resizeImage($width, $height, Imagick::FILTER_LANCZOS, $this->sharpness()->blur);
        $this->sharpenAfterDownscale($imagick, $cropWidth, $cropHeight, $width, $height);

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
        if (!$this->allowUpscale && $factor > 1.0) {
            $factor = 1.0;
        }

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
     * Imagick's sepiaToneImage() expects a 0–100 style threshold (≈80 is the usual
     * sweet spot). Do not scale by QuantumRange — on ImageMagick 7 Q16-HDRI that
     * overflows channels into solid / unreadable colors.
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param int $threshold Sepia threshold 0–100 (default 80). Lower ≈ harsher/yellower; ~80 ≈ classic sepia.
     *
     * @return ImageHandle Sepia-toned Imagick handle.
     */
    public function sepia(ImageHandle $handle, int $threshold = 80): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $threshold = max(0, min(100, $threshold));
        $imagick->sepiaToneImage((float) $threshold);

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
        $format = $imagick->getImageFormat() ?: 'PNG';
        $canvas->setImageFormat($format);
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
        /** @var Imagick $source */
        $source = $handle->resource;
        $width = $handle->width + $left + $right;
        $height = $handle->height + $top + $bottom;
        $canvas = new Imagick();
        $canvas->newImage($width, $height, new \ImagickPixel($color));
        $canvas->setImageFormat($source->getImageFormat() ?: 'PNG');
        $canvas->compositeImage($source, Imagick::COMPOSITE_OVER, $left, $top);

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
     * @param bool $cover When true, scale the mark to exactly match the base image size (full-bleed).
     *
     * @return ImageHandle Imagick handle with the watermark composited on top.
     */
    public function watermark(
        ImageHandle $handle,
        string $sourcePath,
        string $position = 'bottom-right',
        float $opacity = 0.5,
        bool $cover = false,
    ): ImageHandle {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $mark = new Imagick($sourcePath);
        if ($cover) {
            $mark->resizeImage($handle->width, $handle->height, Imagick::FILTER_LANCZOS, 1);
            $position = 'center-center';
        }
        $mark->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity, Imagick::CHANNEL_ALPHA);
        [$x, $y] = $this->overlayPosition($handle, $mark, $position);
        $imagick->compositeImage($mark, Imagick::COMPOSITE_OVER, $x, $y);

        return $this->handleFromImagick($imagick);
    }

    /**
     * Draws text onto the image (optional diagonal / full-cover watermark style).
     *
     * @param ImageHandle $handle Source Imagick handle.
     * @param string $content Text to render.
     * @param array<string, mixed> $options Font, size, color, position, opacity, angle, cover, padding.
     *
     * @return ImageHandle Imagick handle with text composited on top.
     */
    public function text(ImageHandle $handle, string $content, array $options = []): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $width = $handle->width;
        $height = $handle->height;

        $color = (string) ($options['color'] ?? '#ffffff');
        $opacity = (float) ($options['opacity'] ?? 0.5);
        $opacity = max(0.0, min(1.0, $opacity));
        $position = (string) ($options['position'] ?? 'center-center');
        $padding = (int) ($options['padding'] ?? 24);
        $cover = filter_var($options['cover'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $font = isset($options['font']) ? (string) $options['font'] : null;
        $explicitSize = isset($options['size']) ? (float) $options['size'] : null;

        $angleOption = $options['angle'] ?? ($cover ? 'diagonal' : 0);
        $angle = is_string($angleOption) && strtolower($angleOption) === 'diagonal'
            ? -rad2deg(atan2($height, $width))
            : (float) $angleOption;

        $rgba = $this->colorWithOpacity($color, $opacity);
        $stroke = $this->colorWithOpacity('#000000', min(1.0, $opacity * 0.55));

        $draw = new ImagickDraw();
        if ($font !== null && $font !== '' && is_readable($font)) {
            $draw->setFont($font);
        } else {
            $defaultFont = $this->defaultFontPath();
            if ($defaultFont !== null) {
                $draw->setFont($defaultFont);
            }
        }
        $draw->setTextAntialias(true);
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);
        $draw->setFillColor(new ImagickPixel($rgba));
        $draw->setStrokeColor(new ImagickPixel($stroke));
        $draw->setStrokeWidth(max(1.0, ($explicitSize ?? 48) / 40));

        $probe = new Imagick();
        $probe->newImage(8, 8, new ImagickPixel('none'));

        $fontSize = $explicitSize ?? 48.0;
        if ($cover || $explicitSize === null && strtolower((string) $angleOption) === 'diagonal') {
            $diag = hypot($width, $height);
            $target = $diag * 0.78;
            $lo = 10.0;
            $hi = max(400.0, $diag);
            $fontSize = 60.0;
            for ($i = 0; $i < 20; $i++) {
                $mid = ($lo + $hi) / 2.0;
                $draw->setFontSize($mid);
                $metrics = $probe->queryFontMetrics($draw, $content);
                $tw = (float) $metrics['textWidth'];
                $th = (float) max($metrics['textHeight'], $metrics['ascender'] - $metrics['descender']);
                $rad = deg2rad(abs($angle));
                $bw = $tw * cos($rad) + $th * sin($rad);
                $bh = $tw * sin($rad) + $th * cos($rad);
                if ($bw <= $width * 0.96 && $bh <= $height * 0.96 && $tw <= $target) {
                    $lo = $mid;
                    $fontSize = $mid;
                } else {
                    $hi = $mid;
                }
            }
        }

        $draw->setFontSize($fontSize);
        $draw->setStrokeWidth(max(1.0, $fontSize / 48));
        $metrics = $probe->queryFontMetrics($draw, $content);
        $tw = (int) ceil($metrics['textWidth'] + 8);
        $th = (int) ceil(max($metrics['textHeight'], $metrics['ascender'] - $metrics['descender']) + 8);

        $strip = new Imagick();
        $strip->newImage(max(1, $tw), max(1, $th), new ImagickPixel('none'));
        $strip->setImageFormat('png32');
        $strip->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
        $draw->annotation(4, (int) round($metrics['ascender'] + 4), $content);
        $strip->drawImage($draw);

        if (abs($angle) > 0.01) {
            $strip->rotateImage(new ImagickPixel('none'), $angle);
            $strip->setImagePage(0, 0, 0, 0);
        }

        if ($cover || strtolower((string) ($options['position'] ?? '')) === 'center-center' || !isset($options['position'])) {
            $x = (int) round(($width - $strip->getImageWidth()) / 2);
            $y = (int) round(($height - $strip->getImageHeight()) / 2);
        } else {
            [$x, $y] = $this->overlayPosition($handle, $strip, $position);
            $x += $this->paddingOffsetX($position, $padding);
            $y += $this->paddingOffsetY($position, $padding);
        }

        $imagick->compositeImage($strip, Imagick::COMPOSITE_OVER, $x, $y);
        $probe->clear();
        $strip->clear();

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
     * Resolves a CSS hex/rgb color to an Imagick rgba() string with opacity.
     *
     * @param string $color Hex (`#rgb` / `#rrggbb`) or Imagick color string.
     * @param float $opacity Alpha from 0.0 to 1.0.
     *
     * @return string Imagick-compatible color string.
     */
    private function colorWithOpacity(string $color, float $opacity): string
    {
        $color = trim($color);
        $opacity = max(0.0, min(1.0, $opacity));

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color, $matches) === 1) {
            $hex = $matches[1];
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));

            return sprintf('rgba(%d,%d,%d,%.4f)', $r, $g, $b, $opacity);
        }

        if (str_starts_with(strtolower($color), 'rgba(') || str_starts_with(strtolower($color), 'rgb(')) {
            return $color;
        }

        return sprintf('rgba(255,255,255,%.4f)', $opacity);
    }

    /**
     * Picks a readable system font path for text rendering when none is configured.
     *
     * @return string|null Absolute font file path, or null to use Imagick defaults.
     */
    private function defaultFontPath(): ?string
    {
        $candidates = [
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
            '/Library/Fonts/Arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        ];

        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Horizontal padding nudge for named watermark positions.
     *
     * @param string $position Position slug such as "left-top" or "bottom-right".
     * @param int $padding Padding in pixels.
     *
     * @return int Signed X offset.
     */
    private function paddingOffsetX(string $position, int $padding): int
    {
        [$xAlign] = $this->parsePosition($position);

        return match ($xAlign) {
            'left' => $padding,
            'right' => -$padding,
            default => 0,
        };
    }

    /**
     * Vertical padding nudge for named watermark positions.
     *
     * @param string $position Position slug such as "left-top" or "bottom-right".
     * @param int $padding Padding in pixels.
     *
     * @return int Signed Y offset.
     */
    private function paddingOffsetY(string $position, int $padding): int
    {
        [, $yAlign] = $this->parsePosition($position);

        return match ($yAlign) {
            'top' => $padding,
            'bottom' => -$padding,
            default => 0,
        };
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
        try {
            $mime = $imagick->getImageMimeType() ?: 'image/png';
        } catch (\ImagickException) {
            $format = strtolower((string)$imagick->getImageFormat());
            if ($format === '') {
                $imagick->setImageFormat('png');
                $format = 'png';
            }
            $mime = match ($format) {
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'avif' => 'image/avif',
                default => 'image/png',
            };
        }

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
     * Applies a light unsharp mask after downscaling to recover edge contrast.
     *
     * @param Imagick $imagick Image already resized to the target dimensions.
     * @param int $sourceWidth Pre-resize width.
     * @param int $sourceHeight Pre-resize height.
     * @param int $targetWidth Post-resize width.
     * @param int $targetHeight Post-resize height.
     *
     * @return void
     */
    private function sharpenAfterDownscale(
        Imagick $imagick,
        int $sourceWidth,
        int $sourceHeight,
        int $targetWidth,
        int $targetHeight,
    ): void {
        if ($targetWidth >= $sourceWidth && $targetHeight >= $sourceHeight) {
            return;
        }

        $unsharp = $this->sharpness()->unsharp;
        if ($unsharp === null) {
            return;
        }

        $imagick->unsharpMaskImage(
            $unsharp['radius'],
            $unsharp['sigma'],
            $unsharp['amount'],
            $unsharp['threshold'],
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

            $targets = [max(1, $width), max(1, (int)round($handle->height * $ratio))];
        } elseif ($height !== null && $width === null) {
            $ratio = $height / max(1, $handle->height);

            $targets = [max(1, (int)round($handle->width * $ratio)), max(1, $height)];
        } else {
            $targets = [max(1, (int)$width), max(1, (int)$height)];
        }

        return $this->limitUpscale($handle->width, $handle->height, $targets[0], $targets[1]);
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
