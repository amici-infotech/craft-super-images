<?php

namespace amici\SuperImages\contracts;

use amici\SuperImages\models\EncodedImage;

interface OptimizerInterface
{
    public function name(): string;

    public function supports(string $format): bool;

    public function optimize(EncodedImage $encoded, string $format, array $options = []): EncodedImage;
}
