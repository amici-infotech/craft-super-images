<?php
/**
 * Blur effect operation.
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
 * Blur Operation
 *
 * Applies a blur effect to the image. Driver-specific options apply:
 * GD: `passes` (default: 1); Imagick: `radius`, `sigma` (default: 1.0); libvips: `sigma` (default: 1.0).
 * Supported drivers: GD, Imagick, libvips.
 */
final class Blur extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'blur';
    }

    /**
     * Blurs the image via the active driver.
     *
     * @param ImageHandle $handle The image to blur.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The blurred image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        return match (true) {
            $driver instanceof GdDriver => $driver->blur($handle, (int)($this->options['passes'] ?? 1)),
            $driver instanceof ImagickDriver => $driver->blur(
                $handle,
                (float)($this->options['radius'] ?? 1.0),
                (float)($this->options['sigma'] ?? 1.0),
            ),
            $driver instanceof LibvipsDriver => $driver->blur($handle, (float)($this->options['sigma'] ?? 1.0)),
            default => throw new UnsupportedOperationException('Blur is not supported by the selected driver.'),
        };
    }
}
