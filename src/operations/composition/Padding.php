<?php

namespace amici\SuperImages\operations\composition;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Padding extends AbstractOperation
{
    public function name(): string
    {
        return 'padding';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $top = (int)($this->options['top'] ?? $this->options['size'] ?? 0);
        $right = (int)($this->options['right'] ?? $this->options['size'] ?? 0);
        $bottom = (int)($this->options['bottom'] ?? $this->options['size'] ?? 0);
        $left = (int)($this->options['left'] ?? $this->options['size'] ?? 0);
        $color = (string)($this->options['color'] ?? '#ffffff');

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver => $driver->padding($handle, $top, $right, $bottom, $left, $color),
            default => throw new UnsupportedOperationException('Padding is not supported by the selected driver.'),
        };
    }
}
