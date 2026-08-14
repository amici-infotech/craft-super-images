<?php
/**
 * Saturation color adjustment operation.
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
 * Saturation Operation
 *
 * Adjusts color saturation by the given level.
 * Supported options: `level` or `amount` (default: 0).
 * Supported drivers: Imagick only.
 */
final class Saturation extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'saturation';
    }

    /**
     * Applies the saturation adjustment via the active driver.
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
            $driver instanceof ImagickDriver => $driver->saturation($handle, $level),
            default => throw new UnsupportedOperationException('Saturation is not supported by the selected driver.'),
        };
    }
}
