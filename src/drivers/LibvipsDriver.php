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
use amici\SuperImages\models\OperationDefinition;
use amici\SuperImages\models\SourceImage;
use amici\SuperImages\support\LibvipsCliBridge;
use Jcupitt\Vips\Config as VipsConfig;
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
     * Cached native-library probe so auto-fallback does not retry FFI every call.
     */
    private static ?bool $usable = null;

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
     * Checks whether libvips can actually process images in this SAPI.
     *
     * Under FPM isolation the native `vips` binary alone is enough for common
     * transforms. The PHP worker path additionally requires FFI enabled.
     * In-process mode requires a successful native library probe.
     *
     * @return bool True when this driver is safe to select for generation.
     */
    public function isAvailable(): bool
    {
        if (self::$usable !== null) {
            return self::$usable;
        }

        if (!class_exists(Image::class)) {
            return self::$usable = false;
        }

        if (LibvipsCliBridge::shouldIsolate()) {
            // FPM must not use in-process FFI. Usable when the vips binary or the
            // PHP worker responds (worker passes -d ffi.enable=true itself).
            return self::$usable = LibvipsCliBridge::isCliAvailable();
        }

        return self::$usable = $this->probeNativeLibrary();
    }

    /**
     * Whether FFI is loaded and enabled for php-vips in this process.
     */
    private function ffiReady(): bool
    {
        if (!extension_loaded('ffi')) {
            return false;
        }

        $ffiEnable = strtolower((string) ini_get('ffi.enable'));

        return in_array($ffiEnable, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Mark libvips unusable after a native load failure so later selects skip it.
     */
    public function markUnusable(): void
    {
        self::$usable = false;
    }

    /**
     * Whether this process should run libvips work in a CLI child.
     */
    public function usesProcessIsolation(): bool
    {
        return LibvipsCliBridge::shouldIsolate();
    }

    /**
     * Load, apply operations, and encode via native `vips` or one PHP CLI worker.
     *
     * @param list<OperationDefinition> $operations
     */
    public function processAndEncodeIsolated(
        SourceImage $source,
        array $operations,
        string $format,
        EncodeOptions $options,
        int $maxSourcePixels = 0,
    ): EncodedImage {
        $normalized = $this->normalizeFormat($format);
        $ext = $normalized === 'jpeg' ? 'jpg' : $normalized;
        $encodedOutPath = $this->tempIsolatedPath($ext);

        $binaryResult = $this->tryNativeVipsThumbnail(
            $source,
            $operations,
            $encodedOutPath,
            $options,
            $maxSourcePixels,
        );
        if ($binaryResult !== null) {
            return $binaryResult;
        }

        $job = [
            'action' => 'pipeline',
            'sourcePath' => (string) ($source->path ?? ''),
            'mime' => $source->mime ?? 'image/jpeg',
            'format' => $format,
            'encodedOutPath' => $encodedOutPath,
            'maxSourcePixels' => $maxSourcePixels,
            'operations' => array_map(
                static fn (OperationDefinition $op): array => $op->toArray(),
                $operations,
            ),
            'options' => [
                'quality' => $options->quality,
                'stripMetadata' => $options->stripMetadata,
                'extra' => $options->extra,
            ],
            'allowUpscale' => $this->allowUpscale,
            'sharpness' => $this->sharpness()->toIdentityArray(),
        ];

        if ($source->bytes !== null) {
            $job['sourceBytesBase64'] = base64_encode($source->bytes);
        }

        $result = LibvipsCliBridge::run($job);
        $path = (string) ($result['path'] ?? $encodedOutPath);
        if ($path === '' || !is_file($path)) {
            throw new ProcessingException('Libvips CLI pipeline did not produce an encoded file.');
        }

        $bytes = file_get_contents($path);
        @unlink($path);
        if ($bytes === false) {
            throw new ProcessingException('Failed to read libvips CLI pipeline output.');
        }

        return new EncodedImage(
            $normalized,
            (int) ($result['width'] ?? 0),
            (int) ($result['height'] ?? 0),
            strlen($bytes),
            $this->formatMime($normalized),
            $bytes,
        );
    }

    /**
     * Prefer the native `vips` binary for common fit/fill/crop + encode jobs.
     *
     * @param list<OperationDefinition> $operations
     */
    private function tryNativeVipsThumbnail(
        SourceImage $source,
        array $operations,
        string $encodedOutPath,
        EncodeOptions $options,
        int $maxSourcePixels,
    ): ?EncodedImage {
        if ($source->path === null || !is_readable($source->path)) {
            return null;
        }

        if (LibvipsCliBridge::resolveVipsBinary() === null) {
            return null;
        }

        // Bytes-only sources and multi-step pipelines stay on the PHP worker.
        if ($source->bytes !== null || count($operations) !== 1) {
            return null;
        }

        $op = $operations[0];
        $type = strtolower($op->type);
        if (!in_array($type, ['fit', 'fill', 'crop', 'resize'], true)) {
            return null;
        }

        $width = isset($op->options['width']) ? (int) $op->options['width'] : null;
        $height = isset($op->options['height']) ? (int) $op->options['height'] : null;
        if (($width === null || $width <= 0) && ($height === null || $height <= 0)) {
            return null;
        }

        if ($maxSourcePixels > 0) {
            $info = @getimagesize($source->path);
            if (is_array($info) && (($info[0] * $info[1]) > $maxSourcePixels)) {
                return null;
            }
        }

        $crop = in_array($type, ['fill', 'crop'], true);
        $quality = $options->quality;
        if ($quality === null) {
            $quality = match ($this->normalizeFormat((string) pathinfo($encodedOutPath, PATHINFO_EXTENSION))) {
                'jpeg', 'jpg' => 82,
                'webp' => 80,
                'avif' => 65,
                default => null,
            };
        }

        try {
            $result = LibvipsCliBridge::runThumbnail($source->path, $encodedOutPath, [
                'width' => $width,
                'height' => $height,
                'crop' => $crop,
                'quality' => $quality,
                'strip' => $options->stripMetadata,
                'effort' => isset($options->extra['effort'])
                    ? (int) $options->extra['effort']
                    : 0,
            ]);
        } catch (\Throwable) {
            return null;
        }

        $bytes = file_get_contents($result['path']);
        @unlink($result['path']);
        if ($bytes === false) {
            return null;
        }

        $normalized = $this->normalizeFormat((string) pathinfo($encodedOutPath, PATHINFO_EXTENSION));

        return new EncodedImage(
            $normalized === 'jpg' ? 'jpeg' : $normalized,
            $result['width'],
            $result['height'],
            strlen($bytes),
            $this->formatMime($normalized === 'jpg' ? 'jpeg' : $normalized),
            $bytes,
        );
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
        if (LibvipsCliBridge::shouldIsolate()) {
            return $this->loadIsolated($source);
        }

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
        if ($this->isIsolatedHandle($handle)) {
            return $this->encodeIsolated($handle, $format, $options);
        }

        /** @var Image $image */
        $image = $handle->resource;
        $format = $this->normalizeFormat($format);

        if (in_array($format, ['jpeg', 'jpg'], true) && (int) $image->bands >= 4) {
            $background = $this->parseBackgroundRgb((string) ($options->extra['background'] ?? '#ffffff'));
            $image = $image->flatten(['background' => $background]);
        }

        $saveOptions = match ($format) {
            'jpeg', 'jpg' => ['Q' => $options->qualityOrDefault(82)],
            'webp' => ['Q' => $options->qualityOrDefault(80)],
            // Default effort 0: libvips AVIF effort 4+ is an order of magnitude slower
            // under FPM isolation and dominates srcset generation time.
            'avif' => [
                'Q' => $options->qualityOrDefault(65),
                'effort' => max(0, min(9, (int) ($options->extra['effort'] ?? 0))),
            ],
            'png' => [],
            default => throw new UnsupportedFormatException(sprintf('Format "%s" is not supported by Libvips.', $format)),
        };

        $progressive = (bool)($options->extra['progressive'] ?? false);
        if ($progressive && in_array($format, ['jpeg', 'jpg'], true)) {
            $saveOptions['interlace'] = true;
        }

        if ($format === 'png' && array_key_exists('pngCompression', $options->extra)) {
            $saveOptions['compression'] = max(0, min(9, (int)$options->extra['pngCompression']));
        }

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
        if ($this->isIsolatedHandle($handle)) {
            $path = (string) ($handle->resource['path'] ?? '');
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
        // In-process Libvips Image objects are garbage-collected.
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
        if ($this->isIsolatedHandle($handle)) {
            return $this->runIsolatedOp($handle, 'resize', [$width, $height, $mode]);
        }

        /** @var Image $image */
        $image = $handle->resource;
        [$targetWidth, $targetHeight] = $this->resolveTargetDimensions($handle, $width, $height, $mode);
        $resized = $image->resize($targetWidth / $handle->width, ['vscale' => $targetHeight / $handle->height]);
        $resized = $this->sharpenAfterDownscale($resized, $handle->width, $handle->height, $targetWidth, $targetHeight);

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
        if ($this->isIsolatedHandle($handle)) {
            return $this->runIsolatedOp($handle, 'crop', [$width, $height, $position]);
        }

        [$width, $height] = $this->limitUpscale($handle->width, $handle->height, $width, $height);
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
        $cropped = $this->sharpenAfterDownscale($cropped, $cropWidth, $cropHeight, $width, $height);

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
        if ($this->isIsolatedHandle($handle)) {
            return $this->runIsolatedOp($handle, 'scale', [$factor]);
        }

        if (!$this->allowUpscale && $factor > 1.0) {
            $factor = 1.0;
        }

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
        if ($this->isIsolatedHandle($handle)) {
            return $this->runIsolatedOp($handle, 'rotate', [$angle]);
        }

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
        if ($this->isIsolatedHandle($handle)) {
            return $this->runIsolatedOp($handle, 'flip', [$direction]);
        }

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
        if ($this->isIsolatedHandle($handle)) {
            return $this->runIsolatedOp($handle, 'brightness', [$amount]);
        }

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
        if ($this->isIsolatedHandle($handle)) {
            return $this->runIsolatedOp($handle, 'contrast', [$amount]);
        }

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
        if ($this->isIsolatedHandle($handle)) {
            return $this->runIsolatedOp($handle, 'grayscale', []);
        }

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
        if ($this->isIsolatedHandle($handle)) {
            return $this->runIsolatedOp($handle, 'sharpen', [$amount]);
        }

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
        if ($this->isIsolatedHandle($handle)) {
            return $this->runIsolatedOp($handle, 'blur', [$sigma]);
        }

        /** @var Image $image */
        $image = $handle->resource;

        return $this->handleFromImage($image->gaussblur($sigma), $handle->mime);
    }

    /**
     * Applies a light libvips sharpen after downscaling when configured.
     *
     * @param Image $image Image already resized to the target dimensions.
     * @param int $sourceWidth Pre-resize width.
     * @param int $sourceHeight Pre-resize height.
     * @param int $targetWidth Post-resize width.
     * @param int $targetHeight Post-resize height.
     *
     * @return Image Possibly sharpened image.
     */
    private function sharpenAfterDownscale(
        Image $image,
        int $sourceWidth,
        int $sourceHeight,
        int $targetWidth,
        int $targetHeight,
    ): Image {
        if ($targetWidth >= $sourceWidth && $targetHeight >= $sourceHeight) {
            return $image;
        }

        $unsharp = $this->sharpness()->unsharp;
        if ($unsharp === null) {
            return $image;
        }

        $sigma = max(0.1, (float) $unsharp['sigma']);

        return $image->sharpen(['sigma' => $sigma]);
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
     * @return list<int>
     */
    private function parseBackgroundRgb(string $color): array
    {
        $color = ltrim(trim($color), '#');
        if (strlen($color) === 6 && ctype_xdigit($color)) {
            return [
                (int) hexdec(substr($color, 0, 2)),
                (int) hexdec(substr($color, 2, 2)),
                (int) hexdec(substr($color, 4, 2)),
            ];
        }

        return [255, 255, 255];
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

    /**
     * Probes php-vips FFI so a missing dylib is treated as unavailable, not a runtime error.
     */
    private function probeNativeLibrary(): bool
    {
        if (!class_exists(Image::class) || !$this->ffiReady()) {
            return false;
        }

        try {
            // PHP-FPM + FFI is fragile with libvips thread pools on some macOS builds.
            // Cap concurrency before any image work (also set via env when possible).
            if (getenv('VIPS_CONCURRENCY') === false || getenv('VIPS_CONCURRENCY') === '') {
                putenv('VIPS_CONCURRENCY=1');
            }
            VipsConfig::version();
            if (method_exists(VipsConfig::class, 'concurrencySet')) {
                VipsConfig::concurrencySet(max(1, (int) (getenv('VIPS_CONCURRENCY') ?: 1)));
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array{path?: string}|mixed $resource
     */
    private function isIsolatedHandle(ImageHandle $handle): bool
    {
        return is_array($handle->resource) && (($handle->resource['isolated'] ?? false) === true);
    }

    private function loadIsolated(SourceImage $source): ImageHandle
    {
        $outPath = $this->tempIsolatedPath();
        $job = [
            'action' => 'load',
            'sourcePath' => (string) ($source->path ?? ''),
            'outPath' => $outPath,
            'mime' => $source->mime ?? 'image/jpeg',
            'allowUpscale' => $this->allowUpscale,
            'sharpness' => $this->sharpness()->toIdentityArray(),
        ];

        if ($source->bytes !== null) {
            $job['sourceBytesBase64'] = base64_encode($source->bytes);
        }

        $result = LibvipsCliBridge::run($job);
        return $this->handleFromIsolatedResult($result, $source->mime ?? 'image/jpeg');
    }

    /**
     * @param list<mixed> $args
     */
    private function runIsolatedOp(ImageHandle $handle, string $method, array $args): ImageHandle
    {
        $inPath = (string) ($handle->resource['path'] ?? '');
        $outPath = $this->tempIsolatedPath();
        $result = LibvipsCliBridge::run([
            'action' => 'op',
            'method' => $method,
            'args' => $args,
            'inPath' => $inPath,
            'outPath' => $outPath,
            'mime' => $handle->mime,
            'allowUpscale' => $this->allowUpscale,
            'sharpness' => $this->sharpness()->toIdentityArray(),
        ]);

        if ($inPath !== '' && is_file($inPath)) {
            @unlink($inPath);
        }

        return $this->handleFromIsolatedResult($result, $handle->mime);
    }

    private function encodeIsolated(ImageHandle $handle, string $format, EncodeOptions $options): EncodedImage
    {
        $result = LibvipsCliBridge::run([
            'action' => 'encode',
            'inPath' => (string) ($handle->resource['path'] ?? ''),
            'format' => $format,
            'mime' => $handle->mime,
            'options' => [
                'quality' => $options->quality,
                'stripMetadata' => $options->stripMetadata,
                'extra' => $options->extra,
            ],
            'allowUpscale' => $this->allowUpscale,
            'sharpness' => $this->sharpness()->toIdentityArray(),
        ]);

        $bytes = base64_decode((string) ($result['bytes'] ?? ''), true);
        if ($bytes === false) {
            throw new ProcessingException('Libvips CLI encode returned invalid bytes.');
        }

        $normalized = $this->normalizeFormat($format);

        return new EncodedImage(
            $normalized,
            (int) ($result['width'] ?? $handle->width),
            (int) ($result['height'] ?? $handle->height),
            strlen($bytes),
            $this->formatMime($normalized),
            $bytes,
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function handleFromIsolatedResult(array $result, string $mime): ImageHandle
    {
        $path = (string) ($result['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new ProcessingException('Libvips CLI worker did not produce an image file.');
        }

        return new ImageHandle(
            $this->name(),
            [
                'isolated' => true,
                'path' => $path,
            ],
            (int) ($result['width'] ?? 0),
            (int) ($result['height'] ?? 0),
            (bool) ($result['hasAlpha'] ?? false),
            (string) ($result['mime'] ?? $mime),
        );
    }

    private function tempIsolatedPath(string $extension = 'tif'): string
    {
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'super-images-vips';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ProcessingException('Cannot create libvips isolation temp directory.');
        }

        return $dir . DIRECTORY_SEPARATOR . 'img_' . bin2hex(random_bytes(8)) . '.' . ltrim($extension, '.');
    }
}
