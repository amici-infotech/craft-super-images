<?php

namespace amici\SuperImages\contracts;

use amici\SuperImages\models\ImageHandle;

interface OperationInterface
{
    public function name(): string;

    /**
     * @return array<string, mixed>
     */
    public function options(): array;

    public function supports(ImageHandle $handle, ImageDriverInterface $driver): bool;

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle;
}
