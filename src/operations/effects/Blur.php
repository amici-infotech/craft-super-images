<?php

namespace amici\SuperImages\operations\effects;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Blur extends AbstractOperation
{
    public function name(): string
    {
        return 'blur';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        return match (true) {
            $driver instanceof GdDriver => $driver->blur($handle, (int)($this->options['passes'] ?? 1)),
            $driver instanceof ImagickDriver => $driver->blur(
                $handle,
                (float)($this->options['radius'] ?? 1.0),
                (float)($this->options['sigma'] ?? 1.0),
            ),
            $driver instanceof LibvipsDriver => $driver->blur($handle, (float)($this->options['sigma'] ?? 1.0)),
            default => throw new UnsupportedOperationException('Blur is not supported by the selected driver.'),
        };
    }
}
