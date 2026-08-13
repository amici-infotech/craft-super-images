<?php

namespace amici\SuperImages\drivers;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\contracts\OperationInterface;
use amici\SuperImages\exceptions\ProcessingException;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\Dimensions;
use amici\SuperImages\models\ImageHandle;

abstract class AbstractDriver implements ImageDriverInterface
{
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
     * @return array{0: int, 1: int, 2: int, 3: int}
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
     * @return array{0: string, 1: string}
     */
    protected function parsePosition(string $position): array
    {
        $parts = explode('-', $position);
        $x = $parts[0] ?? 'center';
        $y = $parts[1] ?? 'center';

        return [$x, $y];
    }

    protected function alignOffset(int $source, int $crop, string $align): int
    {
        return match ($align) {
            'left', 'top' => 0,
            'right', 'bottom' => max(0, $source - $crop),
            default => max(0, (int)(($source - $crop) / 2)),
        };
    }

    /**
     * @return array{0: int, 1: int}
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

    protected function updateHandleDimensions(ImageHandle $handle, Dimensions $dimensions): ImageHandle
    {
        return $handle->withDimensions($dimensions->width, $dimensions->height);
    }
}
