<?php

namespace amici\SuperImages\operations\geometry;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Fill extends AbstractOperation
{
    public function name(): string
    {
        return 'fill';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $width = (int)($this->options['width'] ?? $handle->width);
        $height = (int)($this->options['height'] ?? $handle->height);
        $position = (string)($this->options['position'] ?? 'center-center');

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver,
            $driver instanceof LibvipsDriver => $driver->fill($handle, $width, $height, $position),
            default => throw new UnsupportedOperationException('Fill is not supported by the selected driver.'),
        };
    }
}
