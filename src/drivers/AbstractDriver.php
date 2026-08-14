<?php
/**
 * Shared image driver helpers and operation dispatch.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\drivers;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\contracts\OperationInterface;
use amici\SuperImages\exceptions\ProcessingException;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\Dimensions;
use amici\SuperImages\models\ImageHandle;

/**
 * Abstract Driver
 *
 * Provides common geometry helpers and delegates supported operations to operation objects.
 */
abstract class AbstractDriver implements ImageDriverInterface
{
    /** @var bool Whether geometry operations may enlarge beyond source dimensions. */
    protected bool $allowUpscale = true;

    /**
     * Controls whether resize, crop, fit, fill, and scale may produce output larger than the source.
     *
     * @param bool $allow When false, target dimensions are clamped to source bounds.
     *
     * @return void
     */
    public function setAllowUpscale(bool $allow): void
    {
        $this->allowUpscale = $allow;
    }

    /**
     * Clamps target dimensions so neither axis exceeds the source when upscaling is disallowed.
     *
     * Preserves the aspect ratio of the requested target pair.
     *
     * @param int $sourceWidth Source image width in pixels.
     * @param int $sourceHeight Source image height in pixels.
     * @param int $targetWidth Requested output width in pixels.
     * @param int $targetHeight Requested output height in pixels.
     *
     * @return array{0: int, 1: int} Tuple of [width, height], each at least 1.
     */
    protected function limitUpscale(
        int $sourceWidth,
        int $sourceHeight,
        int $targetWidth,
        int $targetHeight,
    ): array {
        if ($this->allowUpscale) {
            return [$targetWidth, $targetHeight];
        }

        if ($targetWidth <= $sourceWidth && $targetHeight <= $sourceHeight) {
            return [$targetWidth, $targetHeight];
        }

        $scale = min(
            $sourceWidth / max(1, $targetWidth),
            $sourceHeight / max(1, $targetHeight),
            1.0,
        );

        return [
            max(1, (int)round($targetWidth * $scale)),
            max(1, (int)round($targetHeight * $scale)),
        ];
    }

    /**
     * Applies an operation to an image handle when the driver and operation both support it.
     *
     * @param ImageHandle $handle The current in-memory image handle.
     * @param OperationInterface $operation The operation to apply.
     *
     * @return ImageHandle A new handle reflecting the transformed image.
     *
     * @throws UnsupportedOperationException When the driver or operation cannot process the request.
     */
    public function apply(ImageHandle $handle, OperationInterface $operation): ImageHandle
    {
        if (!$this->supports($operation->name())) {
            throw new UnsupportedOperationException(
                sprintf('Driver "%s" does not support operation "%s".', $this->name(), $operation->name()),
            );
        }

        if (!$operation->supports($handle, $this)) {
            throw new UnsupportedOperationException(
                sprintf('Operation "%s" is not supported for the current image.', $operation->name()),
            );
        }

        return $operation->apply($handle, $this);
    }

    /**
     * Ensures a dimension value is either null or a positive integer.
     *
     * @param int|null $value The dimension value to validate.
     * @param string $label Human-readable label used in error messages.
     *
     * @return int|null The validated dimension, or null when not provided.
     *
     * @throws ProcessingException When the value is zero or negative.
     */
    protected function assertPositiveDimension(?int $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value <= 0) {
            throw new ProcessingException(sprintf('%s must be a positive integer.', $label));
        }

        return $value;
    }

    /**
     * Calculates a source crop box that preserves the target aspect ratio.
     *
     * @param int $sourceWidth Source image width in pixels.
     * @param int $sourceHeight Source image height in pixels.
     * @param int $targetWidth Desired output width in pixels.
     * @param int $targetHeight Desired output height in pixels.
     * @param string $position Crop anchor in the form "xAlign-yAlign" (e.g. "center-center").
     *
     * @return array{0: int, 1: int, 2: int, 3: int} Tuple of [srcX, srcY, cropWidth, cropHeight].
     */
    protected function calculateCropBox(
        int $sourceWidth,
        int $sourceHeight,
        int $targetWidth,
        int $targetHeight,
        string $position = 'center-center',
    ): array {
        $sourceRatio = $sourceWidth / max(1, $sourceHeight);
        $targetRatio = $targetWidth / max(1, $targetHeight);

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int)round($sourceHeight * $targetRatio);
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int)round($sourceWidth / $targetRatio);
        }

        [$xAlign, $yAlign] = $this->parsePosition($position);

        $srcX = $this->alignOffset($sourceWidth, $cropWidth, $xAlign);
        $srcY = $this->alignOffset($sourceHeight, $cropHeight, $yAlign);

        return [$srcX, $srcY, $cropWidth, $cropHeight];
    }

    /**
     * Parses a position string into horizontal and vertical alignment tokens.
     *
     * @param string $position Position string such as "left-top" or "center-center".
     *
     * @return array{0: string, 1: string} Tuple of [xAlign, yAlign].
     */
    protected function parsePosition(string $position): array
    {
        $parts = explode('-', $position);
        $x = $parts[0] ?? 'center';
        $y = $parts[1] ?? 'center';

        return [$x, $y];
    }

    /**
     * Calculates the offset for aligning a crop region within a source dimension.
     *
     * @param int $source Total source dimension (width or height).
     * @param int $crop Crop dimension along the same axis.
     * @param string $align Alignment token: left, right, top, bottom, or center.
     *
     * @return int Pixel offset from the origin.
     */
    protected function alignOffset(int $source, int $crop, string $align): int
    {
        return match ($align) {
            'left', 'top' => 0,
            'right', 'bottom' => max(0, $source - $crop),
            default => max(0, (int)(($source - $crop) / 2)),
        };
    }

    /**
     * Calculates output dimensions that fit within optional max bounds while preserving aspect ratio.
     *
     * @param int $sourceWidth Source image width in pixels.
     * @param int $sourceHeight Source image height in pixels.
     * @param int|null $maxWidth Maximum allowed output width, or null for no limit.
     * @param int|null $maxHeight Maximum allowed output height, or null for no limit.
     *
     * @return array{0: int, 1: int} Tuple of [width, height], each at least 1.
     */
    protected function calculateFitDimensions(
        int $sourceWidth,
        int $sourceHeight,
        ?int $maxWidth,
        ?int $maxHeight,
    ): array {
        $width = $sourceWidth;
        $height = $sourceHeight;

        if ($maxWidth !== null && $width > $maxWidth) {
            $height = (int)round($height * ($maxWidth / $width));
            $width = $maxWidth;
        }

        if ($maxHeight !== null && $height > $maxHeight) {
            $width = (int)round($width * ($maxHeight / $height));
            $height = $maxHeight;
        }

        return [max(1, $width), max(1, $height)];
    }

    /**
     * Returns a copy of the handle with updated width and height metadata.
     *
     * @param ImageHandle $handle The handle whose metadata should be updated.
     * @param Dimensions $dimensions The new dimensions to apply.
     *
     * @return ImageHandle A handle with refreshed dimension metadata.
     */
    protected function updateHandleDimensions(ImageHandle $handle, Dimensions $dimensions): ImageHandle
    {
        return $handle->withDimensions($dimensions->width, $dimensions->height);
    }
}
