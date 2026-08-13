<?php

namespace amici\SuperImages\operations\geometry;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Flip extends AbstractOperation
{
    public function name(): string
    {
        return 'flip';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $direction = (string)($this->options['direction'] ?? 'horizontal');

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver,
            $driver instanceof LibvipsDriver => $driver->flip($handle, $direction),
            default => throw new UnsupportedOperationException('Flip is not supported by the selected driver.'),
        };
    }
}
