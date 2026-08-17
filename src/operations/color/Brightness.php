<?php
/**
 * Brightness color adjustment operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\color;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/** Adjusts image brightness (`level` or `amount`, default 0). */
final class Brightness extends AbstractOperation
{
    public function name(): string
    {
        return 'brightness';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $level = (int)($this->options['level'] ?? $this->options['amount'] ?? 0);

        return $this->invokeDriver($driver, 'brightness', $handle, $level);
    }
}
