<?php

namespace amici\SuperImages\operations\composition;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Border extends AbstractOperation
{
    public function name(): string
    {
        return 'border';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $size = (int)($this->options['size'] ?? 1);
        $color = (string)($this->options['color'] ?? '#000000');

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver => $driver->border($handle, $size, $color),
            default => throw new UnsupportedOperationException('Border is not supported by the selected driver.'),
        };
    }
}
