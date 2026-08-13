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
use Imagick;

final class ImagickDriver extends AbstractDriver
{
    public function name(): string
    {
        return 'imagick';
    }

    public function isAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class);
    }

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
                'brightness', 'contrast', 'saturation', 'grayscale', 'sepia', 'invert',
                'sharpen', 'blur', 'background', 'padding', 'border', 'watermark', 'overlay',
            ],
            formats: ['jpeg', 'jpg', 'png', 'webp', 'avif'],
            supportsAlpha: true,
            supportsWatermark: true,
        );
    }

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

    public function destroy(ImageHandle $handle): void
    {
        if ($handle->resource instanceof Imagick) {
            $handle->resource->clear();
            $handle->resource->destroy();
        }
    }

    public function resize(ImageHandle $handle, ?int $width, ?int $height, string $mode = 'fit'): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        [$targetWidth, $targetHeight] = $this->resolveTargetDimensions($handle, $width, $height, $mode);
        $imagick->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1);

        return $this->handleFromImagick($imagick);
    }

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

    public function rotate(ImageHandle $handle, float $angle, string $background = 'transparent'): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->rotateImage(new \ImagickPixel($background), $angle);

        return $this->handleFromImagick($imagick);
    }

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

    public function brightness(ImageHandle $handle, int $level): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->modulateImage(100 + $level, 100, 100);

        return $this->handleFromImagick($imagick);
    }

    public function contrast(ImageHandle $handle, int $level): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->sigmoidalContrastImage($level > 0, abs($level) / 10, 0);

        return $this->handleFromImagick($imagick);
    }

    public function saturation(ImageHandle $handle, int $level): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->modulateImage(100, 100 + $level, 100);

        return $this->handleFromImagick($imagick);
    }

    public function grayscale(ImageHandle $handle): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->modulateImage(100, 0, 100);

        return $this->handleFromImagick($imagick);
    }

    public function sepia(ImageHandle $handle, int $threshold = 80): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->sepiaToneImage($threshold * Imagick::getQuantumRange()['quantumRangeLong'] / 100);

        return $this->handleFromImagick($imagick);
    }

    public function invert(ImageHandle $handle): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->negateImage(false);

        return $this->handleFromImagick($imagick);
    }

    public function sharpen(ImageHandle $handle, float $amount = 1.0): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->sharpenImage($amount, 1);

        return $this->handleFromImagick($imagick);
    }

    public function blur(ImageHandle $handle, float $radius = 1.0, float $sigma = 1.0): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $imagick->blurImage($radius, $sigma);

        return $this->handleFromImagick($imagick);
    }

    public function background(ImageHandle $handle, string $color): ImageHandle
    {
        /** @var Imagick $imagick */
        $imagick = clone $handle->resource;
        $canvas = new Imagick();
        $canvas->newImage($handle->width, $handle->height, new \ImagickPixel($color));
        $canvas->compositeImage($imagick, Imagick::COMPOSITE_OVER, 0, 0);

        return $this->handleFromImagick($canvas);
    }

    public function padding(ImageHandle $handle, int $top, int $right, int $bottom, int $left, string $color = '#ffffff'): ImageHandle
    {
        $width = $handle->width + $left + $right;
        $height = $handle->height + $top + $bottom;
        $canvas = new Imagick();
        $canvas->newImage($width, $height, new \ImagickPixel($color));
        $canvas->compositeImage($handle->resource, Imagick::COMPOSITE_OVER, $left, $top);

        return $this->handleFromImagick($canvas);
    }

    public function border(ImageHandle $handle, int $size, string $color = '#000000'): ImageHandle
    {
        return $this->padding($handle, $size, $size, $size, $size, $color);
    }

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
     * @return array{0: int, 1: int}
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
            default => throw new UnsupportedFormatException(sprintf('Format "%s" is not supported by Imagick.', $format)),
        };
    }
}
