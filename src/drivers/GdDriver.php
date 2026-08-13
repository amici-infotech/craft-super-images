<?php

namespace amici\SuperImages\drivers;

use amici\SuperImages\exceptions\ProcessingException;
use amici\SuperImages\exceptions\UnsupportedFormatException;
use amici\SuperImages\models\Dimensions;
use amici\SuperImages\models\DriverCapabilities;
use amici\SuperImages\models\EncodeOptions;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\models\SourceImage;

final class GdDriver extends AbstractDriver
{
    public function name(): string
    {
        return 'gd';
    }

    public function isAvailable(): bool
    {
        return extension_loaded('gd');
    }

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

    public function dimensions(ImageHandle $handle): Dimensions
    {
        return new Dimensions($handle->width, $handle->height);
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, $this->capabilities()->operations, true);
    }

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

    public function destroy(ImageHandle $handle): void
    {
        if (is_resource($handle->resource) || $handle->resource instanceof \GdImage) {
            imagedestroy($handle->resource);
        }
    }

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

    public function fill(ImageHandle $handle, int $width, int $height, string $position = 'center-center'): ImageHandle
    {
        return $this->crop($handle, $width, $height, $position);
    }

    public function scale(ImageHandle $handle, float $factor): ImageHandle
    {
        $width = max(1, (int)round($handle->width * $factor));
        $height = max(1, (int)round($handle->height * $factor));

        return $this->resize($handle, $width, $height, 'scale');
    }

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

    public function flip(ImageHandle $handle, string $direction = 'horizontal'): ImageHandle
    {
        $mode = $direction === 'vertical' ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL;
        $resource = $this->cloneImage($handle->resource);
        imageflip($resource, $mode);

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, $handle->hasAlpha, $handle->mime);
    }

    public function brightness(ImageHandle $handle, int $level): ImageHandle
    {
        $resource = $this->cloneImage($handle->resource);
        imagefilter($resource, IMG_FILTER_BRIGHTNESS, $level);

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, $handle->hasAlpha, $handle->mime);
    }

    public function contrast(ImageHandle $handle, int $level): ImageHandle
    {
        $resource = $this->cloneImage($handle->resource);
        imagefilter($resource, IMG_FILTER_CONTRAST, $level);

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, $handle->hasAlpha, $handle->mime);
    }

    public function grayscale(ImageHandle $handle): ImageHandle
    {
        $resource = $this->cloneImage($handle->resource);
        imagefilter($resource, IMG_FILTER_GRAYSCALE);

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, false, $handle->mime);
    }

    public function invert(ImageHandle $handle): ImageHandle
    {
        $resource = $this->cloneImage($handle->resource);
        imagefilter($resource, IMG_FILTER_NEGATE);

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, $handle->hasAlpha, $handle->mime);
    }

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

    public function blur(ImageHandle $handle, int $passes = 1): ImageHandle
    {
        $resource = $this->cloneImage($handle->resource);
        for ($i = 0; $i < max(1, $passes); $i++) {
            imagefilter($resource, IMG_FILTER_GAUSSIAN_BLUR);
        }

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, $handle->hasAlpha, $handle->mime);
    }

    public function background(ImageHandle $handle, string $color): ImageHandle
    {
        $resource = imagecreatetruecolor($handle->width, $handle->height);
        $bg = $this->parseColor($resource, $color);
        imagefilledrectangle($resource, 0, 0, $handle->width, $handle->height, $bg);
        imagecopy($resource, $handle->resource, 0, 0, 0, 0, $handle->width, $handle->height);

        return new ImageHandle($this->name(), $resource, $handle->width, $handle->height, $handle->hasAlpha, $handle->mime);
    }

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

    public function border(ImageHandle $handle, int $size, string $color = '#000000'): ImageHandle
    {
        return $this->padding($handle, $size, $size, $size, $size, $color);
    }

    /**
     * @return \GdImage|resource
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
     * @param \GdImage|resource $resource
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
     * @param \GdImage|resource $resource
     */
    private function preserveAlpha($resource): void
    {
        imagealphablending($resource, false);
        imagesavealpha($resource, true);
    }

    /**
     * @param \GdImage|resource $resource
     */
    private function hasAlpha($resource): bool
    {
        return (imagesx($resource) > 0) && function_exists('imagealphablending');
    }

    /**
     * @param \GdImage|resource $resource
     */
    private function detectMime($resource): string
    {
        return 'image/png';
    }

    /**
     * @return array{0: int, 1: int}
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
     * @param \GdImage|resource $resource
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

    private function normalizeFormat(string $format): string
    {
        $format = strtolower($format);

        return $format === 'jpg' ? 'jpeg' : $format;
    }

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
