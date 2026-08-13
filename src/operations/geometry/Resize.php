<?php

namespace amici\SuperImages\operations\geometry;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

final class Resize extends AbstractOperation
{
    public function name(): string
    {
        return 'resize';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $width = isset($this->options['width']) ? (int)$this->options['width'] : null;
        $height = isset($this->options['height']) ? (int)$this->options['height'] : null;
        $mode = (string)($this->options['mode'] ?? 'fit');

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver,
            $driver instanceof LibvipsDriver => $driver->resize($handle, $width, $height, $mode),
            default => throw new \amici\SuperImages\exceptions\UnsupportedOperationException('Resize is not supported by the selected driver.'),
        };
    }
}
