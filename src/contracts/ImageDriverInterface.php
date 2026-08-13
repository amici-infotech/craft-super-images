<?php

namespace amici\SuperImages\contracts;

use amici\SuperImages\models\Dimensions;
use amici\SuperImages\models\DriverCapabilities;
use amici\SuperImages\models\EncodeOptions;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\models\SourceImage;

interface ImageDriverInterface
{
    public function name(): string;

    public function isAvailable(): bool;

    public function load(SourceImage $source): ImageHandle;

    public function apply(ImageHandle $handle, OperationInterface $operation): ImageHandle;

    public function dimensions(ImageHandle $handle): Dimensions;

    public function supports(string $operation): bool;

    public function capabilities(): DriverCapabilities;

    public function encodeNative(ImageHandle $handle, string $format, EncodeOptions $options): EncodedImage;

    public function destroy(ImageHandle $handle): void;
}
