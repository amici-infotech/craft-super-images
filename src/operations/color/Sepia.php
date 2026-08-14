<?php
/**
 * Sepia tone color operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\color;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Sepia Operation
 *
 * Applies a sepia tone effect to the image.
 * Supported options: `threshold` or `amount` (default: 80).
 * Supported drivers: Imagick only.
 */
final class Sepia extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'sepia';
    }

    /**
     * Applies the sepia effect via the active driver.
     *
     * @param ImageHandle $handle The image to transform.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The sepia-toned image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $threshold = (int)($this->options['threshold'] ?? $this->options['amount'] ?? 80);

        return match (true) {
            $driver instanceof ImagickDriver => $driver->sepia($handle, $threshold),
            default => throw new UnsupportedOperationException('Sepia is not supported by the selected driver.'),
        };
    }
}
