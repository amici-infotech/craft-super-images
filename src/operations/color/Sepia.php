<?php

namespace amici\SuperImages\operations\color;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Sepia extends AbstractOperation
{
    public function name(): string
    {
        return 'sepia';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $threshold = (int)($this->options['threshold'] ?? $this->options['amount'] ?? 80);

        return match (true) {
            $driver instanceof ImagickDriver => $driver->sepia($handle, $threshold),
            default => throw new UnsupportedOperationException('Sepia is not supported by the selected driver.'),
        };
    }
}
