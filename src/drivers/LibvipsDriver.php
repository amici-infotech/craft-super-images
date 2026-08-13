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
use Jcupitt\Vips\Image;

final class LibvipsDriver extends AbstractDriver
{
    public function name(): string
    {
        return 'libvips';
    }

    public function isAvailable(): bool
    {
        return class_exists(Image::class);
    }

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

    public function destroy(ImageHandle $handle): void
    {
        // Libvips Image objects are garbage-collected.
    }

    public function resize(ImageHandle $handle, ?int $width, ?int $height, string $mode = 'fit'): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;
        [$targetWidth, $targetHeight] = $this->resolveTargetDimensions($handle, $width, $height, $mode);
        $resized = $image->resize($targetWidth / $handle->width, ['vscale' => $targetHeight / $handle->height]);

        return $this->handleFromImage($resized, $handle->mime);
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

        /** @var Image $image */
        $image = $handle->resource;
        $cropped = $image->crop($srcX, $srcY, $cropWidth, $cropHeight)->resize(
            $width / $cropWidth,
            ['vscale' => $height / $cropHeight],
        );

        return $this->handleFromImage($cropped, $handle->mime);
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
        /** @var Image $image */
        $image = $handle->resource;
        $scaled = $image->resize($factor, ['vscale' => $factor]);

        return $this->handleFromImage($scaled, $handle->mime);
    }

    public function rotate(ImageHandle $handle, float $angle): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;
        $rotated = $image->rotate($angle);

        return $this->handleFromImage($rotated, $handle->mime);
    }

    public function flip(ImageHandle $handle, string $direction = 'horizontal'): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;
        $flipped = $direction === 'vertical' ? $image->flipvertical() : $image->fliphoriz();

        return $this->handleFromImage($flipped, $handle->mime);
    }

    public function brightness(ImageHandle $handle, float $amount): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;

        return $this->handleFromImage($image->linear([1, 1, 1], [$amount, $amount, $amount]), $handle->mime);
    }

    public function contrast(ImageHandle $handle, float $amount): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;
        $factor = 1 + ($amount / 100);

        return $this->handleFromImage($image->linear([$factor, $factor, $factor], [0, 0, 0]), $handle->mime);
    }

    public function grayscale(ImageHandle $handle): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;

        return $this->handleFromImage($image->colourspace('b-w'), $handle->mime);
    }

    public function sharpen(ImageHandle $handle, float $amount = 1.0): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;

        return $this->handleFromImage($image->sharpen(['sigma' => $amount]), $handle->mime);
    }

    public function blur(ImageHandle $handle, float $sigma = 1.0): ImageHandle
    {
        /** @var Image $image */
        $image = $handle->resource;

        return $this->handleFromImage($image->gaussblur($sigma), $handle->mime);
    }

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
     * @return array{0: int, 1: int}
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
