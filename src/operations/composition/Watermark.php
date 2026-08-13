<?php

namespace amici\SuperImages\operations\composition;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\exceptions\WatermarkSourceException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Watermark extends AbstractOperation
{
    public function name(): string
    {
        return 'watermark';
    }

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
