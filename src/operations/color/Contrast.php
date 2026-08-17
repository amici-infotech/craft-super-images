<?php
/**
 * Contrast color adjustment operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\color;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/** Adjusts image contrast (`level` or `amount`, default 0). */
final class Contrast extends AbstractOperation
{
    public function name(): string
    {
        return 'contrast';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $level = (int)($this->options['level'] ?? $this->options['amount'] ?? 0);

        return $this->invokeDriver($driver, 'contrast', $handle, $level);
    }
}
