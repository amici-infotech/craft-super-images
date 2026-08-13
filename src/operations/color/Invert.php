<?php

namespace amici\SuperImages\operations\color;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Invert extends AbstractOperation
{
    public function name(): string
    {
        return 'invert';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver => $driver->invert($handle),
            default => throw new UnsupportedOperationException('Invert is not supported by the selected driver.'),
        };
    }
}
