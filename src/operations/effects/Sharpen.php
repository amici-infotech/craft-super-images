<?php

namespace amici\SuperImages\operations\effects;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Sharpen extends AbstractOperation
{
    public function name(): string
    {
        return 'sharpen';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $amount = (float)($this->options['amount'] ?? 1.0);

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver,
            $driver instanceof LibvipsDriver => $driver->sharpen($handle, $amount),
            default => throw new UnsupportedOperationException('Sharpen is not supported by the selected driver.'),
        };
    }
}
