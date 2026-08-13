<?php

namespace amici\SuperImages\operations\color;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Saturation extends AbstractOperation
{
    public function name(): string
    {
        return 'saturation';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $level = (int)($this->options['level'] ?? $this->options['amount'] ?? 0);

        return match (true) {
            $driver instanceof ImagickDriver => $driver->saturation($handle, $level),
            default => throw new UnsupportedOperationException('Saturation is not supported by the selected driver.'),
        };
    }
}
