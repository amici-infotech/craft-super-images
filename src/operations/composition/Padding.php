<?php
/**
 * Padding composition operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\composition;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Padding Operation
 *
 * Adds padding around the image on each side.
 * Supported options: `top`, `right`, `bottom`, `left`, or uniform `size` (default: 0), `color` (default: "#ffffff").
 * Supported drivers: GD, Imagick.
 */
final class Padding extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'padding';
    }

    /**
     * Adds padding to the image via the active driver.
     *
     * @param ImageHandle $handle The image to pad.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The padded image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $top = (int)($this->options['top'] ?? $this->options['size'] ?? 0);
        $right = (int)($this->options['right'] ?? $this->options['size'] ?? 0);
        $bottom = (int)($this->options['bottom'] ?? $this->options['size'] ?? 0);
        $left = (int)($this->options['left'] ?? $this->options['size'] ?? 0);
        $color = (string)($this->options['color'] ?? '#ffffff');

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver => $driver->padding($handle, $top, $right, $bottom, $left, $color),
            default => throw new UnsupportedOperationException('Padding is not supported by the selected driver.'),
        };
    }
}
