<?php
/**
 * Sharpen effect operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\effects;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Sharpen Operation
 *
 * Sharpens the image by the given amount.
 * Supported options: `amount` (default: 1.0).
 * Supported drivers: GD, Imagick, libvips.
 */
final class Sharpen extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'sharpen';
    }

    /**
     * Sharpens the image via the active driver.
     *
     * @param ImageHandle $handle The image to sharpen.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The sharpened image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $amount = (float)($this->options['amount'] ?? 1.0);

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver,
            $driver instanceof LibvipsDriver => $driver->sharpen($handle, $amount),
            default => throw new UnsupportedOperationException('Sharpen is not supported by the selected driver.'),
        };
    }
}
