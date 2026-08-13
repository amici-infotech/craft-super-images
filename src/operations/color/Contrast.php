<?php

namespace amici\SuperImages\operations\color;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Contrast extends AbstractOperation
{
    public function name(): string
    {
        return 'contrast';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $level = (int)($this->options['level'] ?? $this->options['amount'] ?? 0);

        return match (true) {
            $driver instanceof GdDriver => $driver->contrast($handle, $level),
            $driver instanceof ImagickDriver => $driver->contrast($handle, $level),
            $driver instanceof LibvipsDriver => $driver->contrast($handle, (float)$level),
            default => throw new UnsupportedOperationException('Contrast is not supported by the selected driver.'),
        };
    }
}
