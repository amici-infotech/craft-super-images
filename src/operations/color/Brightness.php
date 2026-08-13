<?php

namespace amici\SuperImages\operations\color;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Brightness extends AbstractOperation
{
    public function name(): string
    {
        return 'brightness';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $level = (int)($this->options['level'] ?? $this->options['amount'] ?? 0);

        return match (true) {
            $driver instanceof GdDriver => $driver->brightness($handle, $level),
            $driver instanceof ImagickDriver => $driver->brightness($handle, $level),
            $driver instanceof LibvipsDriver => $driver->brightness($handle, (float)$level),
            default => throw new UnsupportedOperationException('Brightness is not supported by the selected driver.'),
        };
    }
}
