<?php
/**
 * Overlay composition operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\composition;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\exceptions\WatermarkSourceException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Overlay Operation
 *
 * Composites an overlay image onto the base image at explicit coordinates.
 * Supported options: `sourcePath` or `path`, `x` (default: 0), `y` (default: 0), `opacity` (default: 1.0).
 * Supported drivers: Imagick only.
 */
final class Overlay extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'overlay';
    }

    /**
     * Applies the overlay via the active driver.
     *
     * @param ImageHandle $handle The base image.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The composited image handle.
     *
     * @throws WatermarkSourceException When the overlay source path is missing or unreadable.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $sourcePath = (string)($this->options['sourcePath'] ?? $this->options['path'] ?? '');
        if ($sourcePath === '' || !is_readable($sourcePath)) {
            throw new WatermarkSourceException('Overlay source path is missing or unreadable.');
        }

        $x = (int)($this->options['x'] ?? 0);
        $y = (int)($this->options['y'] ?? 0);
        $opacity = (float)($this->options['opacity'] ?? 1.0);

        return $this->invokeDriver($driver, 'overlay', $handle, $sourcePath, $x, $y, $opacity);
    }
}
