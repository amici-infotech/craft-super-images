<?php

namespace amici\SuperImages\optimizers;

use amici\SuperImages\contracts\OptimizerInterface;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\support\ProcessRunner;
use amici\SuperImages\support\TemporaryFileManager;

final class NullOptimizer implements OptimizerInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function supports(string $format): bool
    {
        return true;
    }

    public function optimize(EncodedImage $encoded, string $format, array $options = []): EncodedImage
    {
        return $encoded;
    }
}
