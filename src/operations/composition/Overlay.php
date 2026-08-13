<?php

namespace amici\SuperImages\operations\composition;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\exceptions\WatermarkSourceException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Overlay extends AbstractOperation
{
    public function name(): string
    {
        return 'overlay';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $sourcePath = (string)($this->options['sourcePath'] ?? $this->options['path'] ?? '');
        if ($sourcePath === '' || !is_readable($sourcePath)) {
            throw new WatermarkSourceException('Overlay source path is missing or unreadable.');
        }

        $x = (int)($this->options['x'] ?? 0);
        $y = (int)($this->options['y'] ?? 0);
        $opacity = (float)($this->options['opacity'] ?? 1.0);

        return match (true) {
            $driver instanceof ImagickDriver => $driver->overlay($handle, $sourcePath, $x, $y, $opacity),
            default => throw new UnsupportedOperationException('Overlay is not supported by the selected driver.'),
        };
    }
}
