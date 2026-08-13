<?php

namespace amici\SuperImages\operations\geometry;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Rotate extends AbstractOperation
{
    public function name(): string
    {
        return 'rotate';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $angle = (float)($this->options['angle'] ?? 0);

        return match (true) {
            $driver instanceof GdDriver => $driver->rotate($handle, $angle, (int)($this->options['background'] ?? 0)),
            $driver instanceof ImagickDriver => $driver->rotate($handle, $angle, (string)($this->options['background'] ?? 'transparent')),
            $driver instanceof LibvipsDriver => $driver->rotate($handle, $angle),
            default => throw new UnsupportedOperationException('Rotate is not supported by the selected driver.'),
        };
    }
}
