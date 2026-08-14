<?php
/**
 * Brightness color adjustment operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\color;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Brightness Operation
 *
 * Adjusts image brightness by the given level.
 * Supported options: `level` or `amount` (default: 0).
 * Supported drivers: GD, Imagick, libvips.
 */
final class Brightness extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'brightness';
    }

    /**
     * Applies the brightness adjustment via the active driver.
     *
     * @param ImageHandle $handle The image to adjust.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The adjusted image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $level = (int)($this->options['level'] ?? $this->options['amount'] ?? 0);

        return match (true) {
            $driver instanceof GdDriver => $driver->brightness($handle, $level),
            $driver instanceof ImagickDriver => $driver->brightness($handle, $level),
            $driver instanceof LibvipsDriver => $driver->brightness($handle, (float)$level),
            default => throw new UnsupportedOperationException('Brightness is not supported by the selected driver.'),
        };
    }
}
