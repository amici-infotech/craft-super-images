<?php

namespace amici\SuperImages\operations\composition;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Background extends AbstractOperation
{
    public function name(): string
    {
        return 'background';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $color = (string)($this->options['color'] ?? '#ffffff');

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver => $driver->background($handle, $color),
            default => throw new UnsupportedOperationException('Background is not supported by the selected driver.'),
        };
    }
}
