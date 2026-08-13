<?php

namespace amici\SuperImages\operations\geometry;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Fit extends AbstractOperation
{
    public function name(): string
    {
        return 'fit';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $width = isset($this->options['width']) ? (int)$this->options['width'] : null;
        $height = isset($this->options['height']) ? (int)$this->options['height'] : null;

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver,
            $driver instanceof LibvipsDriver => $driver->fit($handle, $width, $height),
            default => throw new UnsupportedOperationException('Fit is not supported by the selected driver.'),
        };
    }
}
