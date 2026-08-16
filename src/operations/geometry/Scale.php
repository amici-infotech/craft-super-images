<?php
/**
 * Scale geometry operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\geometry;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Scale Operation
 *
 * Uniformly scales the image by a multiplicative factor.
 * Supported options: `factor` or `amount` (default: 1.0).
 * Supported drivers: GD, Imagick, libvips.
 */
final class Scale extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'scale';
    }

    /**
     * Scales the image via the active driver.
     *
     * @param ImageHandle $handle The image to scale.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The scaled image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $factor = (float)($this->options['factor'] ?? $this->options['amount'] ?? 1.0);

        return $this->invokeDriver($driver, 'scale', $handle, $factor);
    }
}
