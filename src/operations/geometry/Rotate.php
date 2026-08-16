<?php
/**
 * Rotate geometry operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\geometry;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Rotate Operation
 *
 * Rotates by `angle` degrees. Optional `background`: GD uses a color index,
 * Imagick uses a color string. Custom drivers implementing `rotate()` are supported.
 */
final class Rotate extends AbstractOperation
{
    public function name(): string
    {
        return 'rotate';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $angle = (float)($this->options['angle'] ?? 0);

        if ($driver instanceof GdDriver) {
            return $driver->rotate($handle, $angle, (int)($this->options['background'] ?? 0));
        }

        if ($driver instanceof ImagickDriver) {
            return $driver->rotate($handle, $angle, (string)($this->options['background'] ?? 'transparent'));
        }

        if ($driver instanceof LibvipsDriver) {
            return $driver->rotate($handle, $angle);
        }

        $background = $this->options['background'] ?? 'transparent';

        try {
            return $this->invokeDriver($driver, 'rotate', $handle, $angle, $background);
        } catch (\ArgumentCountError) {
            return $this->invokeDriver($driver, 'rotate', $handle, $angle);
        }
    }
}
