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
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Blur Operation
 *
 * Applies a blur effect. Built-in drivers use different option names:
 * GD `passes`; Imagick `radius`/`sigma`; libvips `sigma`.
 * Third-party drivers implementing `blur($handle, $sigma)` are supported via duck-typing.
 */
final class Blur extends AbstractOperation
{
    public function name(): string
    {
        return 'blur';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $passes = (int)($this->options['passes'] ?? 1);
        $radius = (float)($this->options['radius'] ?? 1.0);
        $sigma = (float)($this->options['sigma'] ?? 1.0);

        if ($driver instanceof GdDriver) {
            return $driver->blur($handle, $passes);
        }

        if ($driver instanceof ImagickDriver) {
            return $driver->blur($handle, $radius, $sigma);
        }

        if ($driver instanceof LibvipsDriver) {
            return $driver->blur($handle, $sigma);
        }

        // Custom drivers: Imagick-like (radius, sigma) when possible, else sigma-only.
        if (is_callable([$driver, 'blur'])) {
            try {
                return $this->invokeDriver($driver, 'blur', $handle, $radius, $sigma);
            } catch (\ArgumentCountError) {
                return $this->invokeDriver($driver, 'blur', $handle, $sigma);
            }
        }

        return $this->invokeDriver($driver, 'blur', $handle, $sigma);
    }
}
