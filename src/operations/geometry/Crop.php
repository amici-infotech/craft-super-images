<?php
/**
 * Crop geometry operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\geometry;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Crop Operation
 *
 * Crops the image to the requested dimensions from a focal position.
 * Supported options: `width`, `height`, `position` (default: "center-center").
 * When only one of `width`/`height` is given, the other is derived proportionally
 * from the source aspect ratio (see {@see AbstractOperation::resolveDimensions()})
 * rather than defaulting to the full source size — this avoids cropping a thin
 * sliver out of the entire source when only a width or height is requested.
 * Supported drivers: GD, Imagick, libvips.
 */
final class Crop extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'crop';
    }

    /**
     * Crops the image via the active driver.
     *
     * @param ImageHandle $handle The image to crop.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The cropped image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        [$width, $height] = $this->resolveDimensions($handle);
        $position = (string)($this->options['position'] ?? 'center-center');

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver,
            $driver instanceof LibvipsDriver => $driver->crop($handle, $width, $height, $position),
            default => throw new UnsupportedOperationException('Crop is not supported by the selected driver.'),
        };
    }
}
