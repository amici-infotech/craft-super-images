<?php
/**
 * Watermark composition operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\composition;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\exceptions\WatermarkSourceException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Watermark Operation
 *
 * Composites a watermark image onto the base image at a named position.
 * Supported options: `sourcePath` or `path`, `position` (default: "bottom-right"), `opacity` (default: 0.5).
 * Supported drivers: Imagick only.
 */
final class Watermark extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'watermark';
    }

    /**
     * Applies the watermark via the active driver.
     *
     * @param ImageHandle $handle The base image.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The watermarked image handle.
     *
     * @throws WatermarkSourceException When the watermark source path is missing or unreadable.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $sourcePath = (string)($this->options['sourcePath'] ?? $this->options['path'] ?? '');
        if ($sourcePath === '' || !is_readable($sourcePath)) {
            throw new WatermarkSourceException('Watermark source path is missing or unreadable.');
        }

        $position = (string)($this->options['position'] ?? 'bottom-right');
        $opacity = (float)($this->options['opacity'] ?? 0.5);

        return match (true) {
            $driver instanceof ImagickDriver => $driver->watermark($handle, $sourcePath, $position, $opacity),
            default => throw new UnsupportedOperationException('Watermark is not supported by the selected driver.'),
        };
    }
}
